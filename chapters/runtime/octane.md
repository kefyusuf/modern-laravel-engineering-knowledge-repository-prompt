---
title: Octane
topic: octane
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
---

# Octane

Octane boots your application once and serves many requests per worker process —
eliminating the framework-boot cost per request. The trade is precise: you accept
responsibility for **state that used to be torn down with each request**. Every
Octane incident is a state-discipline incident; this chapter is the discipline.

## TL;DR

- **Use it when:** request throughput is CPU-bound on framework boot (latency
  budgets unmet by normal FPM, high RPS per node) and the team will hold the state
  discipline this chapter describes.
- **Don't use it when:** FPM already meets your latency budgets, the codebase has
  years of static-state convenience baked in, or nobody owns the memory/perf
  profile.
- **The classic production incident:** user A sees user B's data after a few
  minutes of traffic — a static property (or an unflushed singleton) holding
  per-request state survives into the next request on the same worker. Second
  classic: memory creep that ends in OOM kills every few hours because workers
  never recycle.

## Problem

Under PHP-FPM, each request gets a fresh process: framework boot, request, die.
Everything leaks-proof by construction. Octane keeps the process alive:

- **Container singletons persist** across requests on the same worker — any
  request-scoped data injected into one is now shared across strangers.
- **Static properties persist** — the same, but invisible to the container.
- **Long-lived resources** (sockets, connections) must survive idling and error
  states.

The correctness model changes from "process isolation" to "explicit reset". The
framework resets its own state between requests (it rebinds core request-scoped
services and flushes the configured list); it cannot do that for *your* statics
and *your* half-configured singletons.

## When to use it

- High-throughput JSON APIs where boot time is a measurable share of latency.
- Real-time-ish endpoints (SSE, high-RPS webhooks) that FPM serves expensively.
- Teams already practicing the discipline (no request state in statics; services
  scoped deliberately) — Octane rewards clean architecture, it does not create
  it.

## When NOT to use it

- **FPM meets the budget.** Boot cost only matters when it's your bottleneck;
  profile first — most "slow Laravel" is N+1s and queries
  ([Eloquent](../data/eloquent.md), [Transactions](../data/transactions.md)),
  not boot.
- **Legacy statefulness.** A codebase that stores request state in statics or
  relies on per-request process death will fail under Octane in ways that only
  appear under concurrent traffic — the worst possible test profile.
- **Shared-hosting / restricted runtimes.** Octane needs a long-running process
  manager and (for Swoole/Open Swoole) a PHP extension; if your platform can't
  run daemons, it can't run Octane.

## The simplest correct implementation

Octane 2.x requires Laravel ≥ 10.10.1. Install, choose a runtime, and — before
the first deploy — the state audit:

```text
1. composer require laravel/octane
2. php artisan octane:install          # selects a runtime (see below)
3. php artisan octane:start            # dev; production uses a supervisor
```

**Runtime selection** (all first-class in current Octane):

| Runtime | Choose when | Know |
|---|---|---|
| FrankenPHP | simplest ops — single binary, modern HTTP features | The binary Octane installs ships **its own PHP runtime** (8.4 at the time of writing) — extension/version mismatches with system PHP are possible |
| Swoole / Open Swoole | maximum throughput, Swoole tables/tasks | Needs the PHP extension; the most powerful and the least forgiving |
| RoadRunner | Go-side tooling familiarity, gRPC options | Separate binary + plugin model |

The state audit (the part that matters):

```text
1. grep static properties that hold request-scoped values
2. grep singletons bound with request-scoped constructor deps
3. review the flush list in config/octane.php — every service holding
   request state must be listed in "flush" (or be stateless)
4. verify nothing stores per-request data in class properties of
   singletons (rebind per request, or resolve inside handle())
```

## What production requires

### The flush discipline

`config/octane.php` ships a `flush` list — services the framework re-resolves
between requests. Anything *your* code adds that captures request state must be
in that list, or scoped so it can't capture state. The test for "does this
singleton leak?" is mechanical: set a value in a simulated request, serve a
second, assert the value is gone. Under Octane, that test IS the security
boundary (tenant isolation — the [caching](../data/caching.md) cross-tenant
incident has an Octane twin).

### Worker lifecycle and memory

- **Recycle workers deliberately:** `--max-requests=N` bounds memory creep by
  restarting workers after N requests; size it from measured memory growth, not
  folklore.
- **`octane:reload` on deploy** reloads application code without dropping
  connections; full restart for framework-version changes. Both belong in the
  release script (the queue chapter's `queue:restart` sibling —
  [Queues](../messaging/queues.md)).
- **Supervision:** Octane is a daemon — supervisor/systemd with autorestart,
  like queue workers. It is not a queue worker: heavy async work still belongs
  on queues ([Queues](../messaging/queues.md)).

### The state-leak test (non-negotiable)

A CI test that boots two simulated requests on one worker instance and asserts
tenant-scoped state does not cross. Pair it with the observability tripwire:
context bleeding across tenants in logs ([Observability](../quality/observability.md))
is the production fingerprint of exactly this bug.

## What usually goes wrong

1. **Cross-request state bleed.** *Root cause:* static property or unflushed
   singleton holding per-request/per-tenant data. *Detection:* reports of
   "seeing someone else's data"; log context mismatched to requests. *Fix:* the
   state audit + flush list + the leak test in CI.
2. **Memory creep → OOM kills.** *Root cause:* accumulating containers/arrays in
   long-lived services; unbounded caches in process memory. *Detection:* RSS
   growth per worker; OOM in dmesg every N hours. *Fix:* `--max-requests`
   recycling + fix the accumulator; process-local caching is a cache-chapter
   violation ([Caching](../data/caching.md)).
3. **Stale dependencies after deploy.** *Root cause:* workers running old code
   because reload/restart wasn't in the release flow. *Detection:* "the fix
   isn't deployed" reports with correct artifacts. *Fix:* release script runs
   `octane:reload`; verify with a version endpoint.
4. **Config/`env` cached assumptions.** *Root cause:* `env()` calls outside
   config files — under Octane (like any production setup with config caching)
   they read nothing. *Detection:* null configs in production only. *Fix:*
   `config()` everywhere; `env()` only in `config/`.
5. **Blocking work on the request path.** *Root cause:* CPU-heavy or
   long-I/O work inside requests monopolizing workers — Octane doesn't fix slow
   code, it concentrates it. *Detection:* worker saturation with trivial DB
   time. *Fix:* queues for the work; more workers only for genuinely parallel
   load.
6. **Octane tasks used as a queue.** *Root cause:* `Octane::tick()` / task
   workers treated as durable background processing. They are in-process
   conveniences, not delivery guarantees. *Fix:* real queues with the retry
   and idempotency contract ([Queues](../messaging/queues.md)).

## How to test it

- **State-leak test:** two simulated requests, one worker; assert
  tenant/request-scoped values do not survive. This is the chapter's core
  claim; run it in CI against the production container bindings.
- **Concurrency smoke:** hammer a tenant-scoped endpoint with two tenants and
  assert responses never cross — catches wiring that unit tests miss.
- **Memory profile:** a soak script (N thousand requests) asserting RSS stays
  under a bound, guarding `--max-requests` sizing.
- The harness in this repository does not run Octane (no Swoole/FrankenPHP
  runtime in the CI matrix) — the ⚙️ marks in related chapters cover the
  framework behaviors; Octane-specific behavior is verified in its own soak and
  leak tests above.

## How to operate it

- **Per-worker metrics:** RSS, request rate, p95 per worker — saturation and
  memory are THE two Octane signals.
- **Release script:** `composer install` → config/route cache → `octane:reload`
  (or restart) → health check. Same discipline as `queue:restart`.
- **Capacity math:** workers × requests-per-second-per-worker vs traffic; the
  boot-time win only counts if workers aren't saturated by blocking work.
- **3am runbook:** OOM → check the accumulator, tune `--max-requests`; data
  bleed → treat as security incident, find the static/singleton (the leak test
  reproduces it), flush/scope it; workers pegged → profile the endpoint,
  offload to queues.

## How it scales

- **Horizontal scaling first:** Octane workers scale with CPU; add nodes before
  exotic tuning.
- **Swoole tables / external stores** for shared cross-worker state — but most
  "shared state" is really cache or database
  ([Caching](../data/caching.md)) — reach for in-process shared memory only
  with a consistency story.
- **The ceiling:** blocking I/O and CPU-bound handlers dominate; beyond that,
  the scaling conversation is architecture (queues, read models), not runtime.
- **FrankenPHP specifics:** the bundled binary's PHP version is independent of
  system PHP — pin and monitor it like a runtime dependency.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Octane 2.x supports the framework | ✅ (≥10.10.1) | ✅ | ✅ | ✅ |
| FrankenPHP as a runtime | ✅ (since Dec 2023) | ✅ | ✅ | ✅ |
| Slim-skeleton compatibility | n/a | ✅ | ✅ | ✅ |
| Minimum PHP (framework) | 8.1 | 8.2 | 8.2 | 8.3 |

Octane 2's composer constraint is `laravel/framework ^10.10.1` — the same
package spans this handbook's whole matrix. The FrankenPHP binary bundled by
Octane ships its own PHP (8.4 at the time of writing, 2026-11): treat it as a
pinned runtime, independent of your system PHP.

## Related

- [Queues](../messaging/queues.md) — heavy work stays on queues; Octane is not
  a worker manager.
- [Caching](../data/caching.md) — process-local state under Octane is a cache
  with a cross-tenant failure mode.
- [Observability](../quality/observability.md) — the context-bleed tripwire and
  per-worker signals.
- [The modular monolith](../architecture/modular-monolith.md) — clean module
  boundaries make the state audit small.
- Roadmap: FrankenPHP (standalone, beyond Octane), deployment (supervisor
  templates).
