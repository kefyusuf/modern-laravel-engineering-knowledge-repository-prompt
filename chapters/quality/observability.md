---
title: Observability
topic: observability
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/ObservabilityExamplesTest.php
---

# Observability

Observability is the difference between "the queue is slow" and "the webhook
delivery job's p95 grew 40% after the last deploy because its dedupe lookups lost
an index". This chapter defines the minimum production setup: request context that
survives process boundaries, structured logs worth searching, and the small set of
signals that answer 3am questions. Every other chapter's *How to operate it*
section assumes this one.

## TL;DR

- **Use it when:** anything runs in production — this is not optional
  infrastructure, it's the interface to your own system.
- **Don't do it:** as a log-volume dump. The unit of observability is the
  *correlated event with context*, not the debug print.
- **The classic production incident:** a support ticket says "customer X's order
  failed" and nobody can find X's failed request among millions of unstructured
  lines — no request id in logs, no tenant id in job logs, error traces decaying
  into a generic "500" metric. Second classic: job logs that can't be told apart
  because every worker logs "processed" without the job id.

## Problem

Three questions decide incident resolution time:

1. **What is this log line about?** (Which request? Which tenant? Which job
   attempt?)
2. **What changed?** (Latency, error rate, queue depth — as series, not as
   anecdotes.)
3. **Where did it stop being correct?** (The trace across request → queue → job →
   downstream call.)

Laravel's logging is good but passive: without deliberate context, `Log::info`
lines are anonymous. The framework ships the machinery — `Log::withContext` for
request-scoped enrichment and the `Context` facade for metadata that must survive
into queued jobs — the work is wiring it once and keeping it disciplined.

## When to use it

- Always, with this shape:
  - **Context:** request id, tenant id, user id, job id/attempt — attached at the
    boundary, present on every line downstream.
  - **Structured logs:** key-value context, not string interpolation.
  - **Metrics:** the four golden signals (latency, traffic, errors, saturation)
    per chapter's *operate* section — queue depth, relay lag, dedupe-hit rate,
    lock timeouts.
  - **Alerts on symptoms users feel** (latency, failures, staleness), never on
    causes alone (CPU).

## When NOT to use it (the anti-patterns, precisely)

- **String-interpolated logs.** `"Order {$id} shipped for {$customer}"` is
  unqueryable. `Log::info('order.shipped', ['order_id' => …, 'customer_id' => …])`
  is a search field.
- **Logging PII into shared telemetry.** Names, emails, tokens — use
  `Context::addHidden()` for values jobs need but logs must not show.
- **Alerting on everything.** An alert that fires weekly trains humans to ignore
  it. Alert on user-visible symptoms with a runbook; everything else is a
  dashboard.
- **Tracing everything from day one.** Correlated logs + four metrics cover most
  teams' incidents; distributed tracing is the next step when request graphs
  cross service boundaries ([modular monolith](../architecture/modular-monolith.md)).

## The simplest correct implementation

Context attached at the boundary, enriched into every log line:

<!-- example: observability/log-context -->
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class CorrelateRequest
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-Id') ?? (string) Str::uuid();

        // Every log line written during this request carries this context.
        Log::withContext([
            'request_id' => $requestId,
            'tenant_id' => $request->header('X-Tenant-Id'),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
```

That one middleware converts "grep for the order number and hope" into "filter by
request_id". Everything else in this chapter builds on it.

## What production requires

### Context that survives the queue hop

Request context is worthless if the job that continues the work logs anonymously.
The `Context` facade (Laravel 11+) is built for exactly this: values added before
dispatch are serialized with the job and re-hydrated when it processes.

<!-- example: observability/job-context -->
```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SettleInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $invoiceId) {}

    public function handle(): void
    {
        // Context::add before dispatch (in the request) is re-hydrated here —
        // tenant_id, request_id, whatever the boundary attached. The log line
        // below is attributable to the originating request.
        Log::withContext(Context::all());

        Log::info('invoice.settle.start', ['invoice_id' => $this->invoiceId]);

        DB::table('invoices')
            ->where('id', $this->invoiceId)
            ->where('status', 'open')
            ->update(['status' => 'settled']);

        Log::info('invoice.settle.done', ['invoice_id' => $this->invoiceId]);
    }
}
```

```php
<?php

// At the dispatch site:
Context::add('tenant_id', $tenant->id);
SettleInvoice::dispatch($invoice->id);
```

Discipline that makes this durable:

- **Hidden context for secrets:** `Context::addHidden('stripe_key', …)` travels
  with the job but stays out of log output.
- **The boundary is the only place context is set.** Middleware for HTTP, the
  scheduler/console kernel for commands, the job boundary for hops — leaf code
  reads context, it doesn't invent it.
- **Attempt numbers matter:** on retries, log `$this->attempts()` — the classic
  "it failed 4 times silently" gap is unlogged retries.

### The four signals, wired

Per concern (not globally), from this handbook's chapters:

| Signal | Source | Chapter |
|---|---|---|
| Queue depth | `queue:monitor` / `QueueBusy` event | [Queues](../messaging/queues.md) |
| Relay lag (oldest pending outbox row) | scheduled metric probe | [Outbox](../messaging/outbox.md) |
| Lock timeouts / duplicate-execution count | exception + dedupe-hit telemetry | [Locking](../data/locking.md), [Idempotency](../messaging/idempotency.md) |
| Hit ratio, long transactions, deadlocks | origin + database telemetry | [Caching](../data/caching.md), [Transactions](../data/transactions.md) |

Emit them as structured events from one place each (a metric helper or the
framework's log channel feeding your collector) — not sprinkled through
controllers.

### Log channels with a purpose

Configure channels per consumer, not per mood: `daily` + structured for search,
a dedicated channel for the outbox relay, `stderr` for containers. Keep the stack
lean; a log pipeline is itself an ops surface.

## What usually goes wrong

1. **Anonymous job logs.** *Root cause:* context set in HTTP only; workers log
   without tenant/request. *Detection:* any incident where "which customer?" has
   no answer. *Fix:* `Context` at dispatch + `Log::withContext(Context::all())`
   in the job boundary.
2. **The unqueryable error.** *Root cause:* interpolated messages; no stable
   event names. *Detection:* grep-based archaeology in incidents. *Fix:* event
   name + key-value context, always; message strings are for humans, context is
   for machines.
3. **Context leaking between requests (Octane).** *Root cause:* state stored in
   statics or flushed-incomplete singletons under Octane; `Log::withContext`
   state from the previous request bleeding into the next. *Detection:* the
   cross-tenant log line — user A's request id on user B's entries. *Fix:* the
   Octane flush list + per-request context discipline
   ([Octane](../runtime/octane.md)).
4. **Alert fatigue.** *Root cause:* alerting on causes (CPU, disk) weekly
   instead of symptoms with runbooks. *Detection:* alerts acknowledged without
   action. *Fix:* fewer, symptom-shaped alerts, each linking its runbook.
5. **Log volume as an outage.** *Root cause:* hot-path debug logs in production;
   log shipping becomes the bottleneck. *Detection:* log pipeline lag, disk
   pressure. *Fix:* levels honored, sampling for high-volume paths, volume
   alerts on the pipeline itself.
6. **PII in context.** *Root cause:* `Log::withContext(['user' => $user->toArray()])`
   convenience. *Detection:* a compliance question, uncomfortably late. *Fix:*
   ids in context; entities stay out; `addHidden` for anything sensitive.

## How to test it

- **Correlation:** fire a request through the middleware, capture
  `MessageLogged` events, assert every record carries `request_id` (⚙️
  `examples/tests/ObservabilityExamplesTest.php`).
- **Queue-hop hydration:** add context, dispatch a job, run one worker cycle
  (`queue:work --once` against the database queue), assert the job saw the
  context — the exact property the incident in *usually goes wrong* #1 depends on.
- **Redaction:** assert `addHidden` values appear in job `Context::all()`-ish
  internals but not in log records.

## How to operate it

- **The dashboard is the contract:** one page per concern with its four signals
  — if a chapter's *operate* section names a metric, it's on a dashboard here.
- **Every alert links a runbook**; a runbook names the dashboard, the first
  query, and the safe action — not a paragraph of theory.
- **Log retention:** hot (searchable, days) vs archive (cheap, months) — and
  know which systems log PII *before* retention policy has to answer for it.
- **3am runbook entry zero:** "find the request id / job id from the customer's
  report" — if that step takes more than a minute, this chapter isn't done.

### Cost attribution — the observability bill, owned

Telemetry spend scales with traffic and verbosity; the discipline that keeps
it honest is **attribution**: every pipeline (log shipping, metrics, tracing,
the [preview environments](../runtime/preview-environments.md) that ship
their own telemetry) has a monthly cost label, and the dashboards that
consume it name their pipeline. The decisions this drives:

- **Sampling budgets per pipeline** — high-volume success paths sample down;
  errors and slow paths stay at 100%.
- **Verbosity as a config, not a code change** — log levels and context
  volume tuned per environment from the cost dashboard.
- **The review cadence:** cost spikes are treated like latency spikes — a
  regression to investigate (a debug log left on, a new high-cardinality
  label), with the same deploy correlation.

## How it scales

- **Context is cheap, telemetry is not:** sample high-volume success paths, keep
  100% of errors and slow paths — the incident always lives in the tail.
- **Centralize collection early** (one sink, structured): grep-across-servers is
  the scaling cliff that forces retrofits.
- **Tracing** (spans across request → queue → downstream) is the next level once
  flows cross service boundaries — the context keys you chose here
  (`request_id`, `tenant_id`) are exactly what trace propagation carries.
- **Retention and cardinality discipline** (no unbounded ids as metric labels)
  are what keep the observability bill from scaling with traffic.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `Log::withContext()` | ✅ | ✅ | ✅ | ✅ |
| `Context` facade | ❌ | ✅ (since 11.0) | ✅ | ✅ |
| `Context::addHidden()` / hidden values | ❌ | ✅ (since 11.0) | ✅ | ✅ |
| Automatic context→job hydration | ❌ | ✅ (since 11.0) | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The `Context` feature shipped with Laravel 11.0 — it does not exist on any 10.x
release (verified against the v10.48.29 framework tag). Code that must run on
Laravel 10 correlates jobs by passing ids explicitly in the job payload instead.

## Related

- [Queues](../messaging/queues.md) — `queue:monitor`, worker supervision, and the
  depth metric this chapter alerts on.
- [The transactional outbox](../messaging/outbox.md) — relay lag as the
  observability contract of the outbox.
- [Locking](../data/locking.md) / [Idempotency](../messaging/idempotency.md) —
  the contention and duplicate signals worth their own metric.
- [Octane](../runtime/octane.md) — the state-leak class that observability
  detects and Octane discipline prevents.
- [The modular monolith](../architecture/modular-monolith.md) — when logs stop
  being enough: tracing across module/event boundaries.
