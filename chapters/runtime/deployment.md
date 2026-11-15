---
title: Deployment
topic: deployment
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
---

# Deployment

Deployment is where code meets the machines that keep it alive — and where most
"Laravel is slow/broken in production" stories actually begin: a migration that
ran before the old workers died, a config cache built with stale env, or a
release with no rollback path. This chapter is the release flow: what runs, in
what order, and what makes each step safe to undo.

## TL;DR

- **Use it (this flow) when:** deploying any Laravel app that runs queues,
  scheduled tasks, or long-lived workers — which is every production app this
  handbook addresses.
- **Don't do it:** ship-and-pray — no health gate, no rollback plan, migrations
  entangled with code versions.
- **The classic production incident:** `php artisan migrate:fresh` or a breaking
  column rename deployed while the old release's workers are still running —
  every request for ~30 seconds of overlap fails. The fix is the
  expand/contract discipline below.

## Problem

A Laravel production system is several cooperating processes: web servers, queue
workers (long-lived), the scheduler, and increasingly Octane workers
([Octane](octane.md)). A deploy must:

1. Land new code **without a gap** in any of them,
2. Land schema changes **compatible with the code still running** during the
   overlap,
3. Restart long-lived processes so they pick up the new code
   (`queue:restart`, `octane:reload`),
4. Verify health **before** declaring success — and roll back when it fails.

The failure mode in naive deploys is treating all four as one `git pull` +
`migrate` — which works in dev because dev has one process and no traffic.

## When to use it

- Every production deploy. The specifics (tooling: Deployer, Envoyer, Forge,
  CI pipelines, Docker) vary; the *invariants* below don't.

## When NOT to use it (the false economies)

- **Deploying schema-breaking changes inline.** "It's a five-second window"
  fails the first time a worker request lands in it. Schema and code versions
  must be decoupled — that's expand/contract.
- **Skipping the health gate.** An unverified deploy is a coin flip broadcast
  to all users at once.
- **Manual steps in the critical path.** Anything a human does under pressure at
  2am belongs in the scripted flow — the runbook references the script, not
  tribal knowledge.

## The simplest correct implementation

The release flow, in order:

```text
1. Build (off the servers):   composer install --no-dev, config/route/event caches
2. Release:                   symlink switch (new release dir), opcache reset
3. Migrate:                   php artisan migrate --force   (expand phase only)
4. Reload long-lived:         php artisan queue:restart
                              php artisan octane:reload        (if Octane)
5. Health gate:               GET /up (+ a real dependency probe)
6. Rollback path:             previous release symlink + known-good procedure
```

On Laravel 11+ the framework ships the health route: **`/up`** runs the
framework's health check (listeners may hook into it) and returns 200 when the
app can serve — wire your load balancer or deploy tool to gate on it. On Laravel
10, add an equivalent endpoint (database ping + queue heartbeat).

Two config facts that bite at deploy time:

- `php artisan config:cache` bakes env into one file: after it, `env()` returns
  null outside `config/` — config-only access from then on
  ([Octane](octane.md) hits the same wall).
- `migrate --force` is required in production (the prompt guard is deliberate);
  scripting the deploy means the `--force` is a contract, not an accident.

## What production requires

### Expand/contract — migrations that survive the overlap

During a deploy, old code and new code run simultaneously. Every migration must
therefore be **backwards compatible** with the previous release:

1. **Expand:** add columns/tables/indexes as nullable or defaulted. Old code
   ignores them; new code writes them.
2. **Migrate data** in batches (a queued job or chunked command
   ([Eloquent](../data/eloquent.md) chunkById)) — not in the migration itself
   for large tables.
3. **Contract (a LATER release):** remove the old column/raise the index — only
   after no running code reads it.

Breaking changes (rename, drop, narrow a type) are always two releases. The
cost is one extra deploy cycle; the benefit is that **rollback stops being a
schema restoration project**.

### Long-lived process discipline

- **Workers:** `queue:restart` after every deploy; supervisor
  `stopwaitsecs` longer than the longest job timeout, or the restart kills
  mid-flight jobs and reintroduces the double-run trap
  ([Queues](../messaging/queues.md)).
- **Octane:** `octane:reload` for app code; full restart for framework/runtime
  changes; verify with a version endpoint ([Octane](octane.md)).
- **Scheduler:** one and only one schedule runner per environment — overlap
  guards (`withoutOverlapping`, `--isolated` on the command) are defense in
  depth, not a substitute for ownership ([Locking](../data/locking.md)).

### Observability at the boundary

- Deploy markers in telemetry (a release id in log context /
  [Observability](../quality/observability.md)) — "errors started at 14:02" is
  only actionable when you can correlate 14:02 with release `abc123`.
- The health gate checks the *system*, not just the process: database reachable,
  queue moving (a heartbeat job), cache reachable ([Redis](../data/redis.md)).

## What usually goes wrong

1. **Breaking migration during worker overlap.** *Root cause:* column dropped /
   renamed while old-release workers still select it. *Detection:* error burst
   exactly spanning the deploy window; failed_jobs spike. *Fix:* expand/contract
   discipline; migrate AFTER code deploy, contract in a later release.
2. **Workers running old code.** *Root cause:* no `queue:restart` in the flow —
   workers hold the previous release in memory indefinitely. *Detection:* "the
   fix isn't deployed" for queue-visible behavior; version endpoints disagree.
   *Fix:* restart as a release step; verify via worker version telemetry.
3. **Stale config cache.** *Root cause:* `config:cache` from the wrong env file,
   or `env()` reads after caching. *Detection:* production-only nulls/defaults.
   *Fix:* config-only access; cache built in the build step, not ad hoc.
4. **No rollback rehearsed.** *Root cause:* rollback exists as a concept, never
   as a procedure. *Detection:* the first rollback attempt is improvised. *Fix:*
   symlink-previous-release + a documented "redeploy N-1" drill; keep migrations
   reversible so code rollback doesn't require schema rollback.
5. **The deploy that "succeeded" unhealthy.** *Root cause:* no health gate —
   traffic resumes onto a broken release. *Detection:* user reports. *Fix:* gate
   on `/up` + dependency probes; auto-rollback on failure.
6. **Caches warmed cold.** *Root cause:* no post-deploy warmup for hot keys.
   *Detection:* p99 spike for the first minutes after each deploy. *Fix:* warmup
   jobs in the release flow ([Caching](../data/caching.md)).

## How to test it

- **The release flow is code** — rehearse it in CI or a staging environment
  end-to-end: deploy, health-gate, exercise critical flows, roll back, health-gate
  again. If the rollback has never run, it doesn't exist.
- **Expand/contract is testable:** with the new code running against the
  pre-contract schema, the previous release's test suite still passes — that
  assertion (old tests green on new schema) is the compatibility gate.
- **The overlap is testable:** run the old release's workers against the migrated
  schema in a staging drill — the detection signal for failure mode #1, reproduced
  before production.
- Framework behaviors exercised here (commit timing, worker lifecycle) have their
  ⚙️ coverage in the referenced chapters; this chapter's claims are about the
  release procedure itself.

## How to operate it

- **One command per deploy** (`deploy`, `rollback`) — every interactive step in
  the critical path is a future incident.
- **Deploy telemetry:** duration per step, health-gate result, post-deploy error
  rate for 15 minutes as an automated watch — the deploy is "done" when the
  watch goes quiet ([observability](../quality/observability.md)).
- **Freeze discipline:** deploys during incidents add variables; the freeze is
  the incident commander's call, not a cultural suggestion.
- **3am runbook:** errors after deploy → deploy marker correlation → rollback
  (rehearsed) or forward-fix, decided by blast radius; worker version mismatch →
  `queue:restart` + verify; health gate failing → do not route traffic, diagnose
  with the gate's probe output.

## How it scales

- **Stateless app servers scale horizontally** — everything shared lives in
  Redis/database ([Redis](../data/redis.md)); deploys roll instance-by-instance
  behind the load balancer with the health gate per instance.
- **The schema-change pipeline is the real scaling constraint** — expand/contract
  discipline plus online DDL tooling keeps large-table changes off the critical
  path.
- **Blue/green and canary** are the same invariants with better blast-radius
  control; adopt them when rollback time matters more than infra cost.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `migrate --force`, `queue:restart`, config/route caches | ✅ | ✅ | ✅ | ✅ |
| Built-in `/up` health route | ❌ (custom endpoint) | ✅ | ✅ | ✅ |
| `schedule:run --isolated` / `Isolatable` commands | ✅ (since 10) | ✅ | ✅ | ✅ |
| Slim skeleton deploy surface (`bootstrap/app.php`) | Kernel-based | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The release invariants above are framework-version-agnostic; `/up` is the main
convenience fork between 10 and 11+.

## Related

- [Queues](../messaging/queues.md) — worker lifecycle, `queue:restart`, and the timeout
  budget the supervisor config must respect.
- [Octane](octane.md) — the long-lived-process discipline for the web tier.
- [Transactions](../data/transactions.md) — the schema-adjacent boundary rules
  migrations must respect.
- [Observability](../quality/observability.md) — deploy markers and the
  post-deploy watch.
- [Redis](../data/redis.md) — the shared stores the health gate probes.
- Covered since this line was written: [CI/CD](ci-cd.md) and
  [FrankenPHP](frankenphp.md).
