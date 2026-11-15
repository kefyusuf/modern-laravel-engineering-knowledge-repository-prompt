---
title: Database performance
topic: database-performance
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/DatabasePerformanceExamplesTest.php
---

# Database performance

Database performance work is 90% one skill: **making the database do less work**
— fewer round trips, fewer rows touched, fewer plans that scan. This chapter
turns that into three concrete disciplines (query counts, access paths, batch
shapes) and one habit (reading the plan, not guessing). The ORM-level costs —
N+1s, hydration, chunking — live in [Eloquent at scale](eloquent.md); this
chapter is the layer under it.

## TL;DR

- **Use it when:** any read/write path has a latency or throughput budget —
  which means: always, in this order: measure → fix access paths → fix query
  counts → fix batch shapes.
- **Don't do it:** by intuition. A "slow query" fixed without reading its plan
  is a coin flip; an index added without a query is a write tax with no reader.
- **The classic production incident:** a listing works fine in dev with 1k rows
  and times out in production with 1M — same code, same ORM calls, but the plan
  switched from an index seek to a full scan. Second classic: offset pagination
  (`skip(100000)->take(20)`) whose page 5000 costs a 100k-row walk — every page,
  every user.

## Problem

Three cost classes sit under every database interaction:

1. **Access path** — for each query: does the engine seek (index) or scan
   (read everything and filter)? A scan is linear in table size; your latency
   chart becomes a copy of your data-growth chart.
2. **Round trips** — N queries where 1 would do (application-side loops, per-row
   inserts). Each trip costs network + parse + plan time, multiplied by count.
3. **Work shape** — forcing the database to materialize, transfer, and let the
   application process data the engine could have filtered, aggregated, or
   paged.

Laravel's abstractions (Eloquent, the query builder) express all three
correctly or incorrectly with equal ease — the framework will faithfully run a
100k-row offset walk all day if you ask it to.

## When to use it

- Latency budgets per endpoint (p95 targets) — measure, then apply the levers.
- Anything whose volume grows with data or traffic: listings, exports,
  feeds, report endpoints.
- Write-heavy paths that loop (`foreach { save(); }`) — batch them.

## When NOT to use it (premature optimization, precisely)

- **Indexing every column "for later".** Every index slows every write on the
  table and costs storage; an index without a query that uses it is pure tax.
  Add indexes when a query needs them (and read the plan to confirm).
- **Denormalizing before measuring.** Caches, read models, and summary tables
  ([Caching](caching.md), [CQRS](../architecture/cqrs.md)) are the expensive
  medicine — take them after access paths and query counts are proven clean.
- **Micro-optimizing ORM internals.** Replacing `->get()` with `->cursor()` to
  save 2ms on a 50-row result is noise; the wins are in plans and round trips.
- **Database-specific syntax sprinkled everywhere.** `whereRaw` with engine
  hints couples every test and every future migration to one engine — isolate
  such escapes.

## The simplest correct implementation

The measurement loop — the thing to install before any optimization:

```php
<?php

// In dev/test only: make every query observable.
DB::enableQueryLog();
// ... the flow under scrutiny ...
foreach (DB::getQueryLog() as $entry) {
    logger()->debug('query', ['sql' => $entry['query'], 'ms' => $entry['time']]);
}
DB::disableQueryLog();
```

Or the production version of the same discipline: slow-query logging on the
database itself + query-count telemetry per request
([Observability](../quality/observability.md)). The loop is the same: count the
queries, read the plans of the slow ones, fix, re-measure.

## What production requires

### Access paths: read the plan, then index for it

An index decision is only real when a specific query needs it. Verify the path
the engine actually takes:

<!-- example: database-performance/index-usage -->
```php
<?php

use Illuminate\Support\Facades\DB;

final class OrderIndexCheck
{
    /**
     * EXPLAIN QUERY PLAN is SQLite's window into the access path (MySQL:
     * EXPLAIN / EXPLAIN ANALYZE; Postgres: EXPLAIN (ANALYZE, BUFFERS) —
     * same discipline, per-engine syntax). The harness asserts the SQLite
     * plan; your staging asserts your engine's.
     *
     * @return array<int, object>
     */
    public function plan(string $sql): array
    {
        return DB::select("EXPLAIN QUERY PLAN {$sql}");
    }
}
```

```php
<?php

// With an index on orders(status, placed_at) — created by a migration:
Schema::table('orders', function (Blueprint $table) {
    $table->index(['status', 'placed_at']);
});

// The plan for a filtered + ordered read:
$plan = (new OrderIndexCheck)->plan(
    "SELECT id FROM orders WHERE status = 'open' ORDER BY placed_at DESC",
);

// SEEK: the engine walks the index (SEARCH ... USING INDEX).
// SCAN: it reads the whole table and filters — the data-growth tax.
```

Index rules that survive production:

- **Compound indexes follow the leftmost rule** — `(status, placed_at)` serves
  filters on `status` (and `status` + range on `placed_at`), not `placed_at`
  alone. Order columns by selectivity-and-filter-shape.
- **The plan is the verdict, not the index list.** After adding an index,
  confirm the query switched from SCAN to SEEK — and that it did so in
  production statistics, not just locally (data distributions change plans).
- **Write cost is real:** every index is a second data structure every
  INSERT/UPDATE maintains ([deployment](../runtime/deployment.md) large-table
  index changes need online DDL care).

### Round trips: one statement beats N

<!-- example: database-performance/bulk-insert -->
```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class ImportRows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $rows) {}

    public function handle(): void
    {
        // ONE round trip for the whole batch (chunked to stay under
        // parameter limits — e.g. 500 rows per statement).
        foreach (array_chunk($this->rows, 500) as $chunk) {
            DB::table('import_rows')->insert($chunk);
        }

        // NOT: foreach ($this->rows as $row) { DB::table(...)->insert($row); }
        // — one trip per row, N times the parse/plan/network cost.
    }
}
```

The executed claim counts the statements: five rows, one query
([examples/tests/DatabasePerformanceExamplesTest.php](../../examples/tests/DatabasePerformanceExamplesTest.php)).

The same discipline covers: aggregate in SQL (`SUM`/`COUNT` in one statement —
never materialize rows to sum them in PHP,
[Eloquent](eloquent.md) hydration), `upsert()` over per-row existence checks
([Idempotency](../messaging/idempotency.md)), and eager loading over lazy
access.

### Work shape: pagination that doesn't degrade

Offset pagination makes the engine *walk and discard* every skipped row —
cost grows linearly with page depth. Keyset (cursor) pagination seeks:

<!-- example: database-performance/keyset-pagination -->
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class OrderFeed
{
    /**
     * Keyset pagination: "give me the 20 rows AFTER this id" — the engine
     * seeks into the index instead of walking past discarded rows. Cost is
     * constant per page regardless of depth.
     *
     * @return array{0: array, 1: int|null} rows + next cursor (null = end)
     */
    public function page(?int $afterId, int $limit = 20): array
    {
        $query = DB::table('orders')
            ->orderBy('id')
            ->limit($limit);

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->get(['id', 'status'])->all();

        return [$rows, count($rows) === $limit ? ($rows[count($rows) - 1]->id ?? null) : null];
    }
}
```

Offset has one legitimate advantage — jump-to-page-N — and one hidden bug: rows
shifting between requests under concurrent writes (duplicated or skipped
entries). For feeds, exports, and infinite scroll, keyset wins on both cost and
correctness; for admin back-office page numbers over a bounded table, offset is
honest enough.

## What usually goes wrong

1. **The plan that flips at scale.** *Root cause:* query correct at seed-data
   volume; at production volume the planner picks a scan (stats changed, index
   unused due to a function-wrapped column: `WHERE YEAR(created_at) = 2026`
   can't seek). *Detection:* slow-query log entries absent in staging; latency
   growing with table size. *Fix:* sargable predicates (unwrap the column),
   index for the real filter shape, verify with the production plan.
2. **Offset pagination cost.** *Root cause:* `->paginate()` with a deep page
   parameter — each request walks all skipped rows. *Detection:* latency
   proportional to page number; IO spikes on "page 400" URLs. *Fix:* keyset
   pagination ([Eloquent](eloquent.md) `cursorPaginate` or the pattern above).
3. **N+1 by aggregation.** *Root cause:* per-row counts/sums in loops
   (`foreach ($merchants as $m) $total += $m->orders->sum(...)`). *Detection:*
   query-count telemetry O(rows). *Fix:* one `groupBy` query, or `withCount`
   ([Eloquent](eloquent.md)).
4. **Row-by-row writes.** *Root cause:* import/sync code inserting or updating
   one row per statement. *Detection:* job duration scales linearly with row
   count; network-bound workers. *Fix:* `insert()` batches, `upsert()`, chunked
   bulk updates.
5. **Index-free foreign keys.** *Root cause:* a `*_id` column added **without
   a foreign-key constraint and without an index** (PostgreSQL never
   auto-indexes FK columns; MySQL/InnoDB auto-creates the index only when the
   constraint itself is declared). The result: every join/filter on it scans.
   *Detection:* slow-query log dominated by that table. *Fix:* index the FK —
   the cheapest win in this chapter.
6. **SELECT * on wide tables for narrow needs.** *Root cause:* hydration of
   every column for a two-field listing; large rows blow the buffer pool.
   *Detection:* network/IO-dominated queries returning 30 columns for a
   3-column screen. *Fix:* `select()` the needed columns (`toBase()` for
   row-shaped reads).

## How to test it

- **Plan assertions (⚙️ `examples/tests/DatabasePerformanceExamplesTest.php`):**
  the harness asserts a filtered+ordered query's SQLite plan USES the index
  (SEEK, not SCAN) — the access-path claim as a test. Engine-specific plans
  are asserted on your real engine in staging; the harness proves the
  *discipline*.
- **Pagination stability:** seed rows, add a row "before" the window mid-read,
  and assert keyset pagination never skips or duplicates while offset does —
  cost AND correctness in one test.
- **Query counts:** `DB::getQueryLog()` (or `DB::whenQueryingForLongerThan`)
  asserting a flow issues exactly N statements — the round-trip regression
  gate. The harness asserts bulk insert = one statement.
- **Latency itself is NOT asserted** in unit tests — timing assertions are the
  flake factory ([testing](../quality/testing.md)); performance budgets are
  verified in staging measurements, not CI.

## How to operate it

- **The two standing signals:** slow-query log (threshold: your p95 budget,
  not a fixed "1s") and query-count-per-request telemetry
  ([Observability](../quality/observability.md)). Everything else in this
  chapter starts from one of those two firing.
- **Index inventory review:** per milestone, diff `SHOW INDEX`-style listings
  against actual query plans — unused indexes are write tax candidates for
  removal (carefully: measure first, drop behind a feature-flagged release).
- **EXPLAIN in the runbook:** every slow-query incident ticket includes the
  plan — the fix conversation starts from evidence, not guesses.
- **3am runbook:** latency spike on one endpoint → its query count first
  (N+1 regression?), then its plan (index dropped? stats flipped?); database
  CPU pegged with trivial app CPU → scans or missing-FK-index joins.

## How it scales

- **Access paths scale with data; round trips scale with traffic** — fix plans
  before sharding anything. Most "we need to shard" moments are actually
  "this query scans" moments.
- **Beyond indexes:** connection pooling (persistent connections / Octane
  considerations — [Octane](../runtime/octane.md)), read replicas for read
  scale ([Transactions](transactions.md) read-your-writes caveat), and
  denormalized read models when the write-model shape genuinely can't serve
  the read ([CQRS](../architecture/cqrs.md)).
- **The ceiling:** a well-indexed single MySQL/Postgres instance is further
  away than most teams think — measure before reaching for infrastructure.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `DB::getQueryLog()` / `enableQueryLog()` | ✅ | ✅ | ✅ | ✅ |
| `cursorPaginate()` (keyset via Eloquent) | ✅ | ✅ | ✅ | ✅ |
| `upsert()` / `insert()` batches | ✅ | ✅ | ✅ | ✅ |
| Index creation via migrations | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The performance surface is database-first and framework-stable — the differences
that matter are engine capabilities (online DDL, planner behavior), not Laravel
versions.

## Related

- [Eloquent at scale](eloquent.md) — the ORM cost layer above this one (N+1,
  hydration, chunking) with its own guardrails.
- [Transactions](transactions.md) — batched writes need correct boundaries.
- [Caching](caching.md) / [CQRS](../architecture/cqrs.md) — the expensive
  medicine after plans and round trips are clean.
- [Observability](../quality/observability.md) — slow-query and query-count
  telemetry that make this chapter's loop close.
- [Testing the failure paths](../quality/testing.md) — why latency is never a
  unit-test assertion.
- Roadmap: sharding strategies, online DDL tooling.
