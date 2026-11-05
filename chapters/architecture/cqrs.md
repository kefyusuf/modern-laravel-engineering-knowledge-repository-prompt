---
title: CQRS
topic: cqrs
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/CqrsExamplesTest.php
---

# CQRS

Command Query Responsibility Segregation means the model you *write* through is not
the model you *read* through. The valuable 80% in Laravel is small: dedicated query
classes for reads, command handlers for writes. The expensive 20% — denormalized
projections and eventual consistency — is where CQRS earns its reputation, good and
bad. This chapter walks the spectrum and tells you where to stop.

## TL;DR

- **Use it when:** reads and writes have genuinely different shapes (dashboards,
  search, reports vs invariant-protecting writes), or a hot read path needs
  denormalization.
- **Don't use it when:** the domain is CRUD-shaped — a command/query split there is
  ceremony that slows every feature.
- **The classic production incident:** a team adopts "CQRS" by adding an event
  sourcing framework, then spends a quarter on replay tooling for a domain with
  five entities. Second classic: a projection that silently stops updating after a
  listener failure — dashboards go stale and nobody notices for days.

## Problem

One model serving both sides bends both:

- **The write model gets read pollution.** Invariant-protecting aggregates grow
  scopes and selects they don't need ("just for the report").
- **The read model gets write constraints.** Reporting queries fight the ORM's
  unit-of-work, produce N+1s, and mutate nothing — yet load Eloquent machinery for
  it.
- **Shapes diverge invisibly.** The read side wants "monthly revenue per merchant
  per product line" — expressing that through the write model's `Order` aggregate
  is a query built of apologies.

## When to use it

- Read/write shape mismatch: complex reports, dashboards, search over data whose
  write path is invariant-heavy.
- Hot read paths that need denormalized data (counts, rollups) to hit latency
  budgets.
- Inside a module ([modular monolith](modular-monolith.md)) as the internal
  structure: commands in, events out, queries for the outside world.

## When NOT to use it

- **CRUD-shaped domains.** If reads are "the rows, maybe filtered", query classes
  add indirection without insight — use Eloquent directly and spend the effort
  elsewhere.
- **Staleness is unacceptable AND shapes differ.** This combination means the real
  requirement is a faster consistent read — optimize the query, index it, or
  reconsider the consistency requirement — not a projection.
- **Speculatively.** "We might need different read models later" is not a
  requirement. The query-object layer (level 1) is cheap to add *when* it's needed.

## The spectrum

Ordered by cost — stop at the first level that satisfies the requirement:

1. **Query objects.** Reads become dedicated classes returning DTOs; writes stay
   plain Eloquent/actions. No new infrastructure, no staleness. *The default
   recommendation.*
2. **Denormalized read tables (same database)** maintained by event listeners.
   Eventually consistent *within one database* — the staleness window is the
   listener's processing time. This level's mechanics (reliably publishing the
   event, idempotently consuming it) are the outbox and idempotency patterns.
3. **Separate read store** (dedicated schema/instance or a search engine). Same
   correctness model as level 2 — a projection is a projection wherever it lives —
   with more ops surface and a bigger staleness window.
4. **Event sourcing.** The write model becomes a log of events; projections are
   derived from the log. Out of scope for this chapter, and out of scope for most
   teams: it changes every part of persistence, debugging, and migration for a
   benefit (temporal queries, audit reconstruction) most products don't need.
   CQRS does **not** require it — the two are conflated more often than paired.

## The simplest correct implementation

Level 1 — a query class and a command handler, no infrastructure:

```php
<?php

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\DTO\MerchantOrderSummary;

final class MerchantOrderQuery
{
    /**
     * Reads return DTOs — the shape the consumer needs, not the shape the
     * tables happen to be. Eloquent is allowed here (reads, no invariants),
     * but the return value is a plain object.
     */
    public function summariesFor(int $merchantId, string $status): array
    {
        return \Illuminate\Support\Facades\DB::table('orders')
            ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
            ->where('orders.merchant_id', $merchantId)
            ->where('orders.status', $status)
            ->groupBy('orders.id', 'orders.placed_at')
            ->orderByDesc('orders.placed_at')
            ->get([
                'orders.id',
                'orders.placed_at',
                \Illuminate\Support\Facades\DB::raw('SUM(order_lines.total) as total'),
                \Illuminate\Support\Facades\DB::raw('COUNT(order_lines.id) as line_count'),
            ])
            ->map(fn ($row) => MerchantOrderSummary::fromRow($row))
            ->all();
    }
}
```

```php
<?php

namespace App\Modules\Orders\Commands;

use Illuminate\Support\Facades\DB;

final class CancelOrderHandler
{
    /**
     * Writes carry invariants and own their transaction. Nothing in here
     * exists to serve a read.
     */
    public function handle(int $orderId, int $actorId): void
    {
        DB::transaction(function () use ($orderId, $actorId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();

            if ($order === null) {
                throw new \App\Modules\Orders\Exceptions\OrderNotFound($orderId);
            }
            if ($order->status !== 'pending') {
                throw new \App\Modules\Orders\Exceptions\NotCancellable($orderId, $order->status);
            }

            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'cancelled',
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
            ]);
        });
    }
}
```

That's it. No bus, no interfaces-with-one-implementation, no event storm. The
separation is real: the query returns a shape; the handler enforces invariants;
neither borrows from the other.

## What production requires (level 2: projections)

When a read path needs denormalized data, the projection is a derived table
maintained by events. Two rules make it production-grade: the event must be
**reliably published** (outbox — [The transactional outbox](../messaging/outbox.md))
and the update must be **idempotent** ([Idempotency](../messaging/idempotency.md)).
The listener itself is small:

<!-- example: cqrs/projection-listener -->
```php
<?php

namespace App\Modules\Analytics\Listeners;

use App\Modules\Orders\Events\OrderShipped;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Queued listener: projection work happens off the request path.
 * The event arrives via the outbox relay's queue job — so the
 * reliability properties (at-least-once) come from that chain, and
 * this handler stays idempotent by construction: it recomputes the
 * row rather than incrementing it.
 */
final class UpdateMerchantShipmentProjection implements ShouldQueue
{
    public function handle(OrderShipped $event): void
    {
        // Recompute-and-write (idempotent under redelivery), never
        // increment (would double-count on the guaranteed duplicate).
        DB::table('merchant_shipment_totals')->updateOrInsert(
            ['merchant_id' => $event->payload['merchant_id']],
            ['shipments_total' => DB::table('orders')
                ->where('merchant_id', $event->payload['merchant_id'])
                ->where('status', 'shipped')
                ->count(),
             'updated_at' => now()],
        );
    }
}
```

What the surrounding system must guarantee for this to work:

- **Publication with the state change:** the outbox row commits with the order
  update; a crash can't orphan the event.
- **Redelivery safety:** the queue delivers at-least-once; the recompute pattern
  above absorbs duplicates. A counter-mutation listener would double-count.
- **Failure visibility:** a dead listener freezes the projection — failed-job
  alerting on this queue is what turns "dashboards are stale" into a page within
  minutes instead of days.
- **Rebuild path:** projections are derived, so they're rebuildable. Ship a
  command that truncates and re-projects from source tables — a hand-rolled loop
  over the source data is fine. Test it before you need it, not during the
  incident.

### The consistency window

Level 2 makes staleness user-visible: the write commits, and the projection updates
a queue-cycle later. Design the UX deliberately:

- **Read-your-writes for the acting user:** after a write, show the write model's
  response (or read the source table for that one query) — don't make the user
  watch their own write fail to appear.
- **Bound and observe the window:** relay cadence + queue depth = the window; if
  it's growing, that's an ops incident, not a UX note.

## What usually goes wrong

1. **Projection drift.** *Root cause:* the projection was edited directly (or a
   second writer exists), so it's no longer derived. *Detection:* mismatch between
   source tables and projection with no event to explain it. *Fix:* one rule —
   projections change only via their listener (and rebuild); enforce in review.
2. **Silent listener death.** *Root cause:* an exception path in the listener,
   failed jobs unworked. *Detection:* "stale dashboards" tickets — too late.
   *Fix:* failed-job alerts on the projection queue + a lag metric (below).
3. **Non-idempotent projections.** *Root cause:* `increment()` in a listener that
   receives at-least-once delivery. *Detection:* totals drift upward over time.
   *Fix:* recompute-and-write, or dedupe on the event id.
4. **Commands as anemic ceremony.** *Root cause:* one command class per field
   update, handler = one line delegating to the model. All cost, no invariants.
   *Detection:* handler-to-feature ratio climbing. *Fix:* commands where
   invariants live; plain actions elsewhere.
5. **Write-model reads leaking back.** *Root cause:* the query class quietly
   reuses the aggregate's scopes/relations for reports; write-side changes break
   reads. *Detection:* query classes importing write-side model internals.
   *Fix:* level-1 queries read tables/DTOs, not aggregates.
6. **Event sourcing as the entry point.** *Root cause:* "CQRS" adopted at level 4
   for a level-1 problem. *Detection:* quarter-long infrastructure work before
   shipping a feature. *Fix:* descend the spectrum; earn each level.

## How to test it

- **Query classes:** seed, call, assert DTO shape and values — no HTTP layer
  needed.
- **Handlers:** invariants directly — the not-cancellable case, the concurrent
  case ([Transactions](../data/transactions.md) test patterns apply).
- **Projections (⚙️ `examples/tests/CqrsExamplesTest.php`):** fire the event,
  assert the projection row reflects the source data; fire it *again* (duplicate
  delivery) and assert the projection is unchanged — the idempotency claim of the
  recompute pattern.
- **Consistency window:** assert the listener's queue work completes within one
  queue cycle (with the sync driver, the test is synchronous by construction).

## How to operate it

- **Projection lag:** `now() - max(projection.updated_at)` vs the newest relevant
  source write. Alert when the window exceeds your documented bound.
- **Listener health:** failure rate on the projection queue; a single persistent
  failure freezes a whole projection — this alert is the difference between
  minutes and days of staleness.
- **Rebuild runbook:** the rebuild command, its runtime at current scale, and
  whether it can run live (recompute-and-write projections can; increment-based
  ones must rebuild from zero — another reason to recompute).
- **3am runbook:** stale projection → listener failures (fix cause, requeue,
  verify lag closes) → if drift, rebuild.

## How it scales

- **Reads scale at level 2 by being tables**: index them, replicate them, cache
  their queries ([Caching](../data/caching.md)) — the projection is yours to
  shape, which is the entire point.
- **The write model stays normal** — its scale problem is contention
  ([Transactions](../data/transactions.md)), not read pollution.
- **When a projection needs its own engine** (full-text search, faceted nav),
  level 3 is the same pattern with a different target — budget the ops surface
  honestly before moving.
- **Level 4 is a scale decision, not an architecture preference:** event sourcing
  buys temporal reconstruction at the cost of a different persistence discipline;
  it's the right answer for a small class of domains.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Queued listeners + `ShouldQueue` | ✅ | ✅ | ✅ | ✅ |
| Event discovery / explicit listener registration | ✅ | ✅ | ✅ | ✅ |
| A formal command bus (`Bus::dispatchCommand`) in core | ❌ | ❌ | ❌ | ❌ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The ❌ row (verified against framework tags 11.15, 12.x, 13.x): no first-class
command-bus (`dispatchCommand`-style) exists in any release this handbook covers —
it is not part of the stable surface this chapter relies on. The portable
mechanism is what level 1 shows: a command class + a handler class invoked
explicitly (or dispatched as a job when async). If you want bus machinery, that's
a deliberate, version-checked addition — not a given.

## Related

- [The modular monolith](modular-monolith.md) — CQRS is the internal structure of
  well-shaped modules.
- [The transactional outbox](../messaging/outbox.md) — how projection-driving
  events get published reliably.
- [Idempotency](../messaging/idempotency.md) — the redelivery contract every
  projection listener must satisfy.
- [Transactions](../data/transactions.md) — the write side's boundary.
- [Caching](../data/caching.md) — projections vs caches: both trade staleness,
  for different guarantees.
- [Queues](../messaging/queues.md) — queued listeners are queue consumers; all
  worker rules apply.
