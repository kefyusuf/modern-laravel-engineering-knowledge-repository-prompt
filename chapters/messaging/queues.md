---
title: Queues
topic: queues
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/QueuesExamplesTest.php
---

# Queues

A queue trades latency for a new class of failure: work that happens *later*, may
happen *twice*, and may happen in a *different order* than you dispatched it. Every
queue decision in this chapter follows from those three facts. Used deliberately,
queues absorb spikes and isolate failures; used casually, they turn "the page was
slow" into "some of the emails sent twice and the CSV never finished".

## TL;DR

- **Use it when:** the request doesn't need the result now, the work is retryable,
  and volume or duration makes synchronous execution a scaling risk.
- **Don't use it when:** the caller needs the outcome to proceed, strict ordering is
  required, or the work is trivial enough that queue overhead dominates.
- **The classic production incident:** the worker's `retry_after` is shorter than the
  job's real duration — the queue "reserves" the job, the worker is still running it,
  the broker hands it to a second worker, and the job executes twice. The fix is a
  timeout/retry_after budget you actually track. The second classic: dispatch inside
  a transaction without `afterCommit` — the worker consumes uncommitted state.

## Problem

Slow requests are the visible symptom; the queue is not automatically the cure. The
real problems queues solve:

- **Bursts.** A deploy triggers 10k notification emails; doing them in-request
  destroys latency and retries destroy correctness.
- **Isolation.** A third-party webhook endpoint is down; that failure must not take
  down checkout. A queue is a failure boundary with retry policy.
- **Scheduling and deferral.** Work belongs at a different time (nightly, or "after
  the customer finishes onboarding").

And the problems queues *introduce* — duplicates (at-least-once delivery), ordering
(no guarantee with N workers), and observability gaps (failure is now asynchronous
and easy to lose) — are the rest of this chapter.

## When to use it

- Deferred, retryable side effects: mail, push, webhooks, media processing,
  syncing to third parties.
- Work with known spikes: report generation, imports/exports, batch updates.
- Integrations whose failures must not block the user flow — the queue plus retry
  policy is the integration's availability story.
- Fan-out from a single trigger: one event, many subscribers
  ([outbox](outbox.md) for the reliable variant).

## When NOT to use it

- **The response depends on the work.** If the user waits for the result, do it in
  the request (optimize it) or design an explicit async UX (job id + polling) —
  don't fake synchronous semantics with a queue and hope.
- **Strict ordering matters.** With multiple workers, job A finishing before job B
  *started* is not guaranteed. If order matters, serialize per entity (single queue
  per entity class, or sequence-checked handlers — see
  [outbox](outbox.md) ordering caveats) or process synchronously.
- **The work is trivial.** A 2ms write does not need a broker round trip; the queue
  adds latency, failure modes, and ops surface.
- **Cross-service state transitions.** A queue to another service is not a
  transaction; commit-ordering rules apply
  ([transactions](../data/transactions.md), [outbox](outbox.md)).

## Driver selection

| Driver | Operations profile | Know before you choose |
|---|---|---|
| `sync` | Runs inline — tests, local dev, tiny tools | Every "queue" failure is a request failure; no retries semantics at all |
| `database` | A jobs table; no extra infrastructure; workers poll | Polling cost at scale; fine to moderate volume; easiest ops story |
| `redis` | Fast, queues as lists; Horizon for ops | Requires Redis ops discipline (memory, persistence) |
| `sqs` | Managed, scales | Visibility timeout = `retry_after` analog; FIFO queues have different semantics (ordering, dedupe) |

Start `database` when ops capacity is scarce and volume is moderate; move to `redis`
when throughput or latency demands it; use `sqs` when managed is worth more than
feature parity. Changing drivers later is mostly config — *unless* your jobs
embed driver-specific behavior (they shouldn't).

## The simplest correct implementation

One job, one responsibility, explicitly typed inputs, and a retry policy:

<!-- example: queues/basic-job -->
```php
<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\InvoiceSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendOrderInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Serializable model references are re-fetched by id on the worker.
     * Pass ids for anything that might be deleted before the job runs;
     * an id that no longer resolves is a clean early-return, not a crash.
     */
    public function __construct(public readonly int $orderId) {}

    public function handle(InvoiceSender $sender): void
    {
        $order = Order::find($this->orderId);

        if ($order === null || $order->invoiced_at !== null) {
            return; // deleted, or already invoiced: not an error
        }

        $sender->send($order);

        $order->update(['invoiced_at' => now()]);
    }
}
```

```php
// Wherever the order is confirmed:
SendOrderInvoice::dispatch($order->id);
```

Note what "minimal" already includes: the guard that makes a re-run harmless (the
`invoiced_at` check — [Idempotency](idempotency.md) is part of the minimal job, not a
production extra) and id-based serialization for survivability.

## What production requires

### A retry policy you can defend

At-least-once delivery means failures *will* re-run the job. Decide, per job class,
how many attempts, on what schedule, and what "permanently failed" triggers:

<!-- example: queues/retry-policy -->
```php
<?php

namespace App\Jobs;

use App\Services\WebhookPinger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;           // total attempts, including the first

    public int $maxExceptions = 3;    // give up earlier on repeated crashes

    public function backoff(): array
    {
        // Exponential with a cap: 10s, 30s, 90s, then 5 minutes...
        return [10, 30, 90, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        // Alternative to $tries: a deadline. Whichever comes first.
        return now()->addHours(24);
    }

    public function __construct(
        public readonly int $endpointId,
        public readonly string $payloadId,
    ) {}

    public function handle(WebhookPinger $pinger): void
    {
        $pinger->deliver($this->endpointId, $this->payloadId);
    }

    public function failed(\Throwable $e): void
    {
        // Ran when the job exhausted its policy: alert, mark, compensate.
        \App\Notifications\WebhookDeliveryFailed::notifyOps($this->endpointId);
    }
}
```

Three different knobs that are easy to confuse: `$tries` bounds *attempts*;
`retryUntil()` bounds *time*; `$maxExceptions` bounds *unhandled crashes* (as opposed
to handled failures). Poison messages — jobs that fail identically every time — are
contained by all three; their destination is the failed-jobs table, which is only
useful if someone works it (see [operate](#how-to-operate-it)).

### Dispatch ordering vs the commit

<!-- example: queues/after-commit-dispatch -->
```php
<?php

namespace App\Actions;

use App\Jobs\SendOrderInvoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class CheckoutComplete
{
    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'completed']);

            // Without ->afterCommit(): the job can be picked up before COMMIT
            // lands — the worker sees status='pending' or no row at all.
            SendOrderInvoice::dispatch($order->id)->afterCommit();
        });
    }
}
```

The semantics and their limits are covered in detail in
[Transactions](../data/transactions.md). The one-line summary: `afterCommit` fixes
the *ordering*, not the *reliability* — a crash between commit and dispatch still
loses the job, which is the outbox's problem to solve.

### Dispatch-time dedupe

<!-- example: queues/unique-job -->
```php
<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshProductAvailability implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 60; // seconds the lock lives after processing

    public function __construct(public readonly int $productId) {}

    /**
     * Dedupe scope: one pending job per product — not per request.
     */
    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(): void
    {
        Product::query()
            ->whereKey($this->productId)
            ->each(fn (Product $p) => $p->recalculateAvailability());
    }
}
```

`ShouldBeUnique` prevents a *second dispatch* while the first is queued/running (lock
held via the cache store — it must be shared, see [Locking](../data/locking.md)). It
does **not** make the job idempotent, and it does not dedupe once processing
finished (that's `uniqueFor`'s TTL). If a duplicate execution would corrupt state,
the handle() body needs its own guard regardless.

### Timeout budgeting — the double-run trap

Three numbers must be consistent, and no framework check enforces them:

- The job's `$timeout` (worker kills the process beyond it).
- The connection's `retry_after` (broker re-releases the job after it).
- The real duration of the worst case.

**Rule: `retry_after` must be comfortably larger than the largest `$timeout` on
jobs that connection processes.** If `retry_after` (say 90s) is shorter than a job
that actually runs 120s, the broker re-releases it at 90s, a second worker starts
the same job, and both run to completion — duplicate side effects, corrupted
aggregates, angry customers. Detection is hard after the fact; budget it up front:

```php
// config/queue.php — the number that must dominate every job timeout
'redis' => [
    // ...
    'retry_after' => 300,   // > max($timeout) across jobs on this connection
    'block_for' => 5,
],
```

```php
// The slowest job on the connection must declare:
public $timeout = 120; // < retry_after, with margin for cleanup
```

### Worker lifecycle

Long-running workers accumulate state: memory growth, stale DB connections after
idle periods, and (after every deploy) old code. Production posture:

- `php artisan queue:restart` after every deploy — workers finish their current job
  and exit; supervisor restarts them on the new code.
- Bound worker runs in noisy apps: `--max-jobs` / `--max-time` give a graceful
  recycle cadence.
- Supervision (supervisor/systemd/docker) with autorestart, and `stopwaitsecs`
  **longer than your longest job timeout**, or deploys kill mid-flight jobs — which
  re-triggers the double-run trap on restart.

## What usually goes wrong

1. **Dispatch-in-transaction race.** *Root cause:* the worker outruns the commit.
   *Detection:* job failures with "not found" or stale-status logic within ~1s of
   enqueue — a signature search in failed_jobs. *Fix:* `->afterCommit()`, or an
   outbox row when it must not be lost ([outbox](outbox.md)).
2. **`retry_after` < job duration.** *Root cause:* timeout budgeting never done.
   *Detection:* the same job appearing twice in logs/DB effects a few minutes apart;
   duplicate webhooks at a partner. *Fix:* raise `retry_after` above max `$timeout`
   with margin; add the budget to review checklists.
3. **Unbounded retries against a dead dependency.** *Root cause:* default retry
   policy on a job whose dependency is down for hours. *Detection:* queue depth and
   error rate dominated by one job class. *Fix:* `retryUntil()` deadlines +
   backoff caps + circuit-breaker posture in the dependency client.
4. **Fat payloads.** *Root cause:* serializing models with relations, or passing
   result sets, into the job. *Detection:* driver storage growth, slow payload
   (de)serialization, broker message-size limits. *Fix:* pass ids; re-fetch on the
   worker; store big payloads in the database or object storage and pass references.
5. **Ordering assumptions.** *Root cause:* "I dispatched A then B" treated as "A
   runs before B" with 8 workers. *Detection:* heisenbugs where state transitions
   interleave; consumers seeing B's effect before A's. *Fix:* serialize per entity
   (dedicated queue + single worker, or `WithoutOverlapping` per entity —
   [Locking](../data/locking.md)), or make handlers order-insensitive.
6. **Workers and stale state.** *Root cause:* memory leaks, dead DB connections
   after idle, old code after deploys. *Detection:* OOM kills, "server has gone
   away" errors after quiet periods, fixes that "didn't deploy". *Fix:*
   `queue:restart` on deploy, `--max-jobs`/`--max-time` cadence, connection
   re-establishment posture (framework reconnects on connection errors; your
   long-idle custom connections may not).

## How to test it

- **Dispatch assertions:** `Queue::fake()` → act → `assertPushed(SendOrderInvoice::class)`.
- **Job logic in isolation:** instantiate and call `handle()` with mocked
  dependencies — queue machinery stays out of the test.
- **Idempotency:** run `handle()` twice on the same inputs; assert the second is a
  no-op. This is the harness's core queue claim (⚙️
  `examples/tests/QueuesExamplesTest.php`).
- **Commit ordering:** with `Queue::fake()` inside a rolled-back transaction,
  assert nothing was pushed (fake + transaction interplay shows the
  `afterCommit` wiring).
- **Failure paths:** throw in a dependency mock; assert `failed()` side effects and
  retry counts against the declared policy.

## How to operate it

- **Supervision:** one worker process per queue/concern (isolation: the noisy
  webhook queue doesn't starve payments), autorestart, `stopwaitsecs` > longest
  job timeout. Horizon if you're on redis — it adds the dashboard, metrics, and
  supervised process management.
- **Depth monitoring:** `php artisan queue:monitor redis:default:1000,...` — a
  command that fires an event when a queue crosses a size threshold; wire it to
  your alerting (scheduled every minute, event to your notification channel).
  Queue depth is the leading indicator for everything in this chapter.
- **Failed-jobs discipline:** `queue:failed` triaged on a cadence;
  `queue:retry <id>` after fixing cause; `queue:prune-failed` with a retention
  window (24–72h) so the table stays a queue, not an archive.
- **Deploys:** `queue:restart` is part of the release script — same as migrations.
- **3am runbook:** depth spike → which job class, is it retrying against a dead
  dependency (throttle upstream), do workers need scale-up; duplicates reported →
  check `retry_after` vs `$timeout` and the idempotency guard; worker restart
  loop → last error in supervisor logs, usually a fatal in a specific job class
  (quarantine that queue).

## How it scales

- **Queues per concern**, workers per queue: the fundamental scaling and isolation
  unit. Payments and exports never share a worker pool.
- **Horizontal workers** scale throughput until the *storage* behind the driver or
  the shared downstream does — queue depth during peak tells you which.
- **Backpressure:** rate-limit dispatch (release with delay when a downstream
  rejects), and cap queue depth as an alert, not a buffer to "monitor later".
- **The next step is the outbox**: queues move work; they don't make *publication*
  reliable. When "did the event get sent?" becomes a correctness question —
  [outbox](outbox.md).
- **Ordering at scale** is the hardest property: per-entity serialization limits
  parallelism by design; audit whether you actually need it before paying.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `$tries` / `retryUntil()` / `$maxExceptions` / `backoff()` | ✅ | ✅ | ✅ | ✅ |
| `ShouldBeUnique` + `uniqueId()` | ✅ | ✅ | ✅ | ✅ |
| `->afterCommit()` / `$afterCommit` | ✅ | ✅ | ✅ | ✅ |
| Job batching (`Bus::batch`) and chains | ✅ | ✅ | ✅ | ✅ |
| `queue:monitor` + `QueueBusy` event | ✅ | ✅ | ✅ | ✅ |
| `--max-jobs` / `--max-time` worker options | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Queue semantics are notably stable across the supportable range — the differences
that matter operationally are in the surrounding stack (Redis, Horizon versions,
SQS client), not the job API. This chapter deliberately makes no claims about which
of these APIs were *introduced* before Laravel 10; if you need intro versions for a
migration doc, check the framework release notes for your target version.

## Related

- [Idempotency](idempotency.md) — the contract that makes at-least-once delivery
  survivable; read before writing a second job.
- [The transactional outbox](outbox.md) — when dispatch ordering isn't enough and
  publication must be committed with the state change.
- [Transactions](../data/transactions.md) — `afterCommit` mechanics and the
  boundary the queue is *outside* of.
- [Locking](../data/locking.md) — `WithoutOverlapping` and unique jobs are cache
  locks; shared-store requirements.
- [Caching](../data/caching.md) — the cache store your uniqueness rides on.
- Roadmap: observability (queue dashboards, tracing spans), deployment (worker
  supervision specifics), Octane (not a queue worker — relation explained there).
