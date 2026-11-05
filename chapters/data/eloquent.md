---
title: Eloquent at scale
topic: eloquent
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/EloquentExamplesTest.php
---

# Eloquent at scale

Eloquent is an ORM with opinions that are correct for screens and expensive for
loops. This chapter is about the three costs that dominate Eloquent at production
load — N+1 queries, per-row hydration overhead, and unbatched set operations — and
the framework guardrails that turn them from incident reviews into test failures.

## TL;DR

- **Use it (guarded) when:** Eloquent's productivity is worth the runtime cost —
  CRUD screens, moderate-volume workflows, anywhere the model IS the feature.
- **Don't use it when:** a read path is hot enough that hydration cost dominates
  (reports, exports, high-QPS endpoints) — go to the query builder or raw rows.
- **The classic production incident:** the admin listing that lazy-loads a relation
  per row — 500 rows, 501 queries, database CPU pegged after a data import doubles
  the row count. Second classic: `chunk()` over a table being mutated, silently
  skipping unvisited rows.

## Problem

Three cost classes, all invisible in development:

1. **N+1 queries.** Each model instance lazily loads relations on access. Code that
   is correct for 10 rows does 1000 queries at 100 rows — linearly worse exactly
   when production traffic grows.
2. **Hydration overhead.** Every Eloquent model costs attribute bookkeeping,
   event wiring, and booted-trait checks. Materializing 50k models to compute one
   aggregate spends most of its time in the ORM, not the work.
3. **Unbatched writes.** `foreach ($rows as $row) { $row->save(); }` is N round
   trips. Set operations (bulk update, upsert) exist for exactly this.

None of these are Eloquent bugs — they're the price of the abstraction, payable by
default until measurement says otherwise.

## When to use it

- Standard application flows where the model graph matches the screen.
- Write paths that benefit from events, casts, and invariants on the model.
- Moderate read volumes where indexes + eager loading keep queries bounded.

## When NOT to use it

- **Hot aggregate reads.** Reports, dashboards, exports — query builder or
  read models ([CQRS](../architecture/cqrs.md)), not 50k hydrated models.
- **Row-by-row writes over large sets.** `upsert()` / bulk updates / one
  statement with a `WHERE` ([Transactions](transactions.md) atomic patterns).
- **Iteration over tables being mutated** without id-based batching (below).

## The simplest correct implementation

The lazy-loading guard — the single highest-leverage setting in this chapter:

<!-- example: eloquent/lazy-loading-guard -->
```php
<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Outside production: lazy loading THROWS instead of silently
        // querying. The N+1 becomes a test failure, not a 3am incident.
        Model::preventLazyLoading(! app()->isProduction());

        // Optionally stricter: also forbid accessing missing attributes
        // and discarding writes silently. Great in tests, loud in dev.
        // Model::shouldBeStrict(! app()->isProduction());
    }
}
```

With this on, the 501-query listing fails in CI with
`LazyLoadingViolationException` instead of shipping. In production the same setting
*logs* violations — your telemetry finds the regression before your customers do.

The production read path pairs the guard with explicit eager loading:

```php
<?php

namespace App\Modules\Orders\Queries;

use App\Models\Order;

final class OpenOrdersQuery
{
    public function get(int $merchantId): array
    {
        // One query for orders, one for ALL their lines, one for ALL
        // their customers — regardless of row count.
        return Order::query()
            ->where('merchant_id', $merchantId)
            ->where('status', 'open')
            ->with(['lines:id,order_id,total', 'customer:id,name'])
            ->orderByDesc('placed_at')
            ->get();
    }
}
```

## What production requires

### Batch iteration that can't skip rows

`chunk()` pages by OFFSET. If rows are inserted or deleted while you iterate, the
offsets shift and **unvisited rows get skipped silently**. `chunkById()` pages by
id and is immune to that drift — the docs say this; the incident reports agree.

<!-- example: eloquent/chunk-by-id -->
```php
<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ArchiveStaleOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // chunkById: stable pagination keyed on the primary key, even if
        // other workers archive orders while this one runs. The where()
        // guard is re-applied per batch — the query builder is cloned
        // internally per chunk, not consumed.
        Order::query()
            ->where('status', 'stale')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    $order->update(['status' => 'archived']);
                }
            });
    }
}
```

Two rules that keep chunking correct:

- **Never mutate the column you paginate by** inside a `chunkById` loop in ways
  that move rows backward past the cursor (archiving here flips `status`, not `id`
  — safe).
- **Ordering caveats:** `chunkById` orders by id; if you need a different order,
  you're likely in `lazyById()->each()` territory or should reconsider — unordered
  offset pagination over a mutating table is the bug, not the fix.

### Hydration cost on hot paths

When the read path only needs values — not identity, events, or casts behavior —
skip the model entirely:

<!-- example: eloquent/base-objects -->
```php
<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;

final class StockReport
{
    /**
     * @return \stdClass[] plain rows, no Eloquent overhead
     */
    public function perSku(): array
    {
        // toBase(): same builder, stdClass results — no models, no events,
        // no casts objects. 3-5x cheaper at 10k+ rows (measure on your
        // schema; the win grows with row count and attribute count).
        return Inventory::query()
            ->select('id', 'available')
            ->where('available', '>', 0)
            ->toBase()
            ->get()
            ->all();
    }
}
```

For aggregates, don't hydrate at all: `DB::table()->groupBy()` / `->count()` /
`->sum()` — the database does the work in one round trip.

### Write discipline

- **Bulk over loop:** `Model::upsert($rows, [...], [...])` and bulk inserts, not
  per-row `save()` — see [Idempotency](../messaging/idempotency.md) for the
  natural-key rules.
- **Mass-assignment guards stay on** (`$fillable`): the guard is free until the
  day it isn't.
- **`casts()` method (Laravel 11+)** over the `$casts` property for new code —
  same behavior, better discoverability; on Laravel 10 keep using `$casts`.

## What usually goes wrong

1. **The N+1 that scales with data.** *Root cause:* relation access in a loop
   without eager loading; correct at seed data size. *Detection:* with
   `preventLazyLoading` outside production, a test failure; in production, query
   counts per request in telemetry spiking with row count. *Fix:* eager load at
   the query boundary (the guard then enforces it).
2. **`chunk()` skipping rows during mutation.** *Root cause:* offset pagination
   over a table being modified mid-iteration. *Detection:* archive/migration jobs
   that "miss" rows with no error; re-runs find more rows each time. *Fix:*
   `chunkById` / `lazyById`.
3. **Hydration storms.** *Root cause:* `Model::all()` feeding a sum/count or an
   export. *Detection:* memory ceilings (memory_get_peak_usage in job telemetry),
   slow jobs where DB time is trivial. *Fix:* aggregates in SQL; `toBase()` for
   row-shaped reads; streaming exports with `lazyById`.
4. **Serialized-model payloads.** *Root cause:* Eloquent models inside queue
   payloads or cache entries — re-fetched by id on the other side, with stale
   partial state in between ([Queues](../messaging/queues.md),
   [Caching](caching.md)). *Detection:* payload sizes; "property on null" for
   deleted rows. *Fix:* ids in payloads; re-fetch at the consumer.
5. **Accessors on hot loops.** *Root cause:* a computed accessor invoked per row
   per render — cheap once, expensive at 10k rows × per request. *Detection:*
   profile-guided; Xdebug/teardown traces on the report endpoint. *Fix:* compute
   in SQL or DTOs for bulk paths.
6. **Strict mode left off in tests.** *Root cause:* `shouldBeStrict` only in dev,
   not the suite. *Detection:* N+1s and missing-attribute bugs reaching staging.
   *Fix:* strict in the test bootstrap — it's the cheapest regression gate here.

## How to test it

- **Lazy loading:** a test that builds a model graph, accesses the relation
  without eager loading, and asserts `LazyLoadingViolationException` — the guard
  is itself the test subject (⚙️ `examples/tests/EloquentExamplesTest.php`).
- **Chunk integrity:** seed N rows, delete visited rows inside the callback, and
  assert `chunkById` still processes every unvisited row while offset `chunk`
  does not — the exact production drift, reproduced deterministically.
- **Hydration shape:** `toBase()` returns `stdClass` rows, not models — pin the
  contract that the hot path isn't silently re-hydrating.

## How to operate it

- **Query-count telemetry per request** (and per job) is the N+1 alarm — an
  endpoint's query count should be O(1) in its inputs, not O(rows).
- **Slow-query log + EXPLAIN** cadence: the fix for an Eloquent-shaped slow query
  is usually an index or an eager load, found faster with the query in hand.
- **Peak memory on batch jobs:** track it in job telemetry; hydration storms show
  up as memory before they show up as latency.
- **3am runbook:** database CPU pegged by a single endpoint → query-count
  telemetry → which relation access loop → eager load or `toBase()`; archive job
  "missing rows" → check for offset-chunk + concurrent mutation.

## How it scales

- Eloquent's cost is per-row; the levers are: fewer rows hydrated (`select`
  discipline, aggregates in SQL), cheaper rows (`toBase()`), bounded loops
  (`chunkById`/`lazyById`), and moving hot reads out of the model entirely
  ([CQRS](../architecture/cqrs.md) level 1).
- The write path scales through set operations and queues, not through clever
  model tricks.
- When a read path's shape diverges from the write model's, that's the CQRS
  signal — Eloquent stays for the write side.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `preventLazyLoading()` / `shouldBeStrict()` | ✅ | ✅ | ✅ | ✅ |
| `chunkById()` / `lazyById()` | ✅ | ✅ | ✅ | ✅ |
| `toBase()` | ✅ | ✅ | ✅ | ✅ |
| `casts()` method on models | ❌ use `$casts` | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The guardrails and batching APIs are stable across the whole range; the only
authoring difference is `casts()` vs `$casts`. `Context` for request/job metadata
is 10.48+ — see [Observability](../quality/observability.md).

## Related

- [Transactions](transactions.md) — batched writes still need correct boundaries.
- [CQRS](../architecture/cqrs.md) — where hot reads go when the model stops
  fitting.
- [Queues](../messaging/queues.md) — why ids, not models, travel in payloads.
- [Caching](caching.md) — caching a hydrated model graph is caching a liability.
- Roadmap: database performance (indexing strategy, EXPLAIN practice).
