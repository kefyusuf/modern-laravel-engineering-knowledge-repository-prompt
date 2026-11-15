---
title: Locking
topic: locking
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/LockingExamplesTest.php
---

# Locking

Locking is mutual exclusion for code that must not interleave. The decision that
matters is not *which mechanism* but *what scope* — which resource, protected against
whom, for how long. Laravel gives you four mechanisms with different guarantees: a
database unique index, pessimistic row locks, optimistic version checks, and cache
atomic locks. Choosing by habit instead of by scope is how double-spend bugs ship.

## TL;DR

- **Use it when:** two processes can read the same state and both act on it —
  read-modify-write, cron-vs-worker races, duplicate schedule ticks, oversell.
- **Don't use it when:** a single atomic statement or a unique index already gives you
  the guarantee; or when idempotency makes interleaving harmless.
- **The classic production incident:** a lock (or mutex) keyed by *user* protecting a
  *global* resource — every user holds "their own" lock and they all mutate the same
  row. Second classic: `withoutOverlapping()` on a job with the `array` cache store —
  each worker has its own store, so the mutex excludes nobody.

## Problem

Three concrete failure classes:

1. **Lost update.** Two requests read `available = 1`, both check "enough", both
   decrement, stock goes to −1. The window between read and write is the bug.
2. **Duplicate execution.** A cron tick overlaps with the previous run still running;
   two workers pick "the next" job; a webhook retries while the first attempt is
   mid-flight. Both copies mutate state.
3. **Uniqueness by exception.** Code assumes "one subscription per user" and enforces
   it with an `if`. Under concurrency, the `if` is two `if`s.

All three are the same shape: check-then-act across a window. A lock closes the
window; an atomic operation or a database constraint eliminates it.

## When to use it

Pick the mechanism by scope — this table is the chapter:

| Situation | Mechanism | Why |
|---|---|---|
| "This value must be unique" (email, SKU, one-per-user) | **DB unique index** | The only *atomic*, crash-safe uniqueness guard. Everything else is advisory. |
| Read a row, decide, write it back | **`lockForUpdate()` inside a transaction** | Row-level, released at commit; works with your existing consistency rules. See [Transactions](../data/transactions.md). |
| The invariant fits in one `WHERE` clause | **Atomic single-statement update** | No lock at all — the check and write are one step. |
| Two writers, low contention, long transactions unacceptable | **Optimistic version column** | Check `version` in the `WHERE`, bump it; retry on zero rows affected. |
| Coordinate *across processes* around a non-DB resource (job dedupe, remote API, cache rebuild) | **`Cache::lock()`** on a shared store | TTL-bounded advisory lock; correct scope key required. |
| A job or schedule tick must not run concurrently with itself | **`withoutOverlapping()` / `ShouldBeUnique`** | Built on cache locks — same shared-store requirement. |

## When NOT to use it

- **Wrapping everything in transactions "for safety".** A transaction without a lock
  does not prevent lost updates (under MySQL's REPEATABLE READ or Postgres's READ
  COMMITTED, two transactions can still read the same old value). Locking discipline
  and transaction discipline are separate decisions.
- **Cache locks as write protection for database state.** They are advisory: a holder
  that expires (TTL) or a process ignoring the lock can still write. The database
  constraint or row lock is the guarantee; the cache lock is coordination.
- **Where idempotency is cheaper.** If the second execution is a no-op by design
  ([Idempotency](../messaging/idempotency.md)), you don't need to exclude it —
  let it run.
- **Long-held locks around external I/O.** Same rule as transactions: an HTTP call
  inside a `lockForUpdate()` or while holding a cache lock turns every competitor
  into a victim of your timeout.
- **Premature sharded locking schemes.** If contention never materialized, a
  per-key lock registry is complexity without a customer.

## The simplest correct implementation

The oversell fix, end to end — one transaction, one row lock, and a unique-index
backstop where uniqueness matters:

<!-- example: locking/db-lock-for-update -->
```php
<?php

namespace App\Actions;

use App\Models\Inventory;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class PurchaseInventory
{
    /**
     * @throws \App\Exceptions\InsufficientStock
     */
    public function execute(int $skuId, int $quantity, int $customerId): Order
    {
        return DB::transaction(function () use ($skuId, $quantity, $customerId) {
            // Pessimistic row lock: concurrent buyers serialize here until
            // this transaction commits or rolls back.
            $inventory = Inventory::query()
                ->whereKey($skuId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventory->available < $quantity) {
                throw new \App\Exceptions\InsufficientStock($skuId);
            }

            $inventory->decrement('available', $quantity);

            $order = Order::create([
                'customer_id' => $customerId,
                'sku_id' => $skuId,
                'quantity' => $quantity,
            ]);

            return $order;
        });
    }
}
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The backstop: if a code path forgets the lock, the database — not
        // the application — enforces "one active order per customer per SKU".
        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['customer_id', 'sku_id', 'status']);
        });
    }
};
```

Why both: the row lock protects the check-then-decrement; the unique index protects
the invariant even when a future code path bypasses the action class. Locks coordinate
correct code; constraints survive incorrect code.

## What production requires

### Cache locks: ownership, expiry, and blocking

`Cache::lock()` is the tool for coordination *outside* the database. Three properties
matter: who holds it, when it dies, and what waiting looks like.

<!-- example: locking/cache-lock-owner -->
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;

final class ReportPublisher
{
    public function publish(string $reportId): void
    {
        // Scope: the report, not the user, not the request.
        $lock = Cache::lock("report:publish:{$reportId}", seconds: 30);

        try {
            // block(): wait up to 30s instead of failing immediately.
            $lock->block(30);

            // ... build and publish the report (bounded, fast work) ...
        } catch (LockTimeoutException) {
            // Another holder is running it; treat as "already in progress".
            return;
        } finally {
            // release() is owner-checked on redis/database/array stores: it
            // does nothing if our lock expired and someone else holds it now.
            $lock->release();
        }
    }
}
```

Rules that keep this correct:

- **TTL must exceed the worst-case work duration.** A lock that expires mid-work lets
  a second holder in. For work whose duration is unbounded, the design is wrong —
  bound the work, or renew the lock.
- **`release()` is owner-checked; `forceRelease()` is an admin action.** Every lock
  holder carries a random owner token (`owner()`); `release()` removes the lock only
  when the token matches (redis, database, and array stores — check your driver, and
  prefer redis for anything mutex-backed). To release from a *different* process by
  design (a coordinator), use `Cache::restoreLock($name, $owner)->release()`. Never
  `forceRelease()` in application code paths.
- **The store must be shared.** Redis or Memcached. The `array` store is per-process —
  perfect for tests, useless as a mutex. Lock support on other stores is not
  something to bet on: treat redis/memcached as the dependable choice for anything
  mutex-backed.
- **A cache lock is not a fencetoken.** It cannot prevent an expired holder from
  writing database state. Anything that matters gets a constraint or a row lock.

### Job and schedule mutexes

<!-- example: locking/without-overlapping -->
```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class RebuildSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function middleware(): array
    {
        // Mutex key: derive from the resource, not the class alone —
        // this key must be identical across every worker process.
        return [
            (new WithoutOverlapping("search-index:rebuild"))
                ->expireAfter(600),
        ];
    }

    public function handle(): void
    {
        // ... rebuild ...
    }
}
```

The mutex behind `withoutOverlapping()` (jobs and scheduler alike) is a cache lock:
it requires a **shared cache store** and its `expireAfter` is your TTL. Two classic
failures: the `array` store in a multi-worker setup (a no-op mutex), and a job that
outlives `expireAfter` (two copies running). The scheduler's `->withoutOverlapping()`
has the same requirement and the same failure mode after a cache flush — a stuck
mutex is cleared by removing the cache key, which is a runbook entry, not a mystery.

### Optimistic locking: when contention is low

Laravel ships no first-class optimistic locking; a version column is three lines and
the right tool when transactions-with-locks cost more than occasional retries:

```php
<?php

namespace App\Actions;

use App\Models\Document;

final class RenameDocument
{
    public function execute(Document $document, string $title, int $expectedVersion): bool
    {
        $affected = Document::query()
            ->whereKey($document->getKey())
            ->where('version', $expectedVersion) // fails if anyone moved first
            ->update([
                'title' => $title,
                'version' => $expectedVersion + 1,
            ]);

        return $affected === 1; // false = someone else won; caller retries or reloads
    }
}
```

No locks are held between requests — the conflict is detected at write time. Choose
this when conflicts are rare; choose `lockForUpdate()` when they're common enough
that retry loops show up in latency.

### Scope before mechanism

<!-- example: locking/lock-scope-mismatch -->
```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SyncCustomerBalance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $customerId,
    ) {}

    public function middleware(): array
    {
        // Wrong scope looks like: new WithoutOverlapping("sync-balance")
        // — two tenants' jobs for two customers would serialize against
        // each other (throughput loss), or worse, a per-user key on a
        // shared resource would fail to serialize at all.
        return [
            (new WithoutOverlapping("balance:sync:{$this->tenantId}:{$this->customerId}"))
                ->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        // ... sync ...
    }
}
```

The checklist for any lock key: **which resource** (name it), **which scope** (tenant?
global?), **which direction** (does the same key appear in every code path that
mutates this resource?). A lock that isn't taken by *all* mutators is a suggestion,
not a lock.

## What usually goes wrong

1. **Lock scope narrower than the resource.** *Root cause:* key derived from the
   actor (user/session) instead of the resource. Every actor gets "their" lock and
   all mutate the same row. *Detection:* duplicate mutations where the guard exists;
   race-reproduction tests pass but production data shows doubles. *Fix:* key from
   the resource id (+ tenant), and inventory every mutator.

2. **Array-store mutex in production.** *Root cause:* `withoutOverlapping()` or
   `ShouldBeUnique` deployed while `CACHE_STORE=array` (default in some scaffolding)
   — each worker excludes only itself. *Detection:* duplicate job executions with
   unique-job semantics configured; works locally (single process), fails with
   workers ≥ 2. *Fix:* shared store (redis) for anything mutex-backed; add a
   config-assertion test.

3. **Lock TTL shorter than the work.** *Root cause:* `Cache::lock($key, 10)` around
   work that takes 40s under load. Holder #2 acquires after expiry; holder #1
   finishes and releases #2's lock — on non-owner-checked drivers, that is. *Detection:*
   overlapping
   execution traces; "lock released by non-owner" symptoms. *Fix:* TTL > worst case,
   owner-checked release (redis/database/array), and the work bounded.

4. **Deadlock via inconsistent lock ordering.** *Root cause:* code path A locks
   order→inventory, path B locks inventory→order. *Detection:* deadlock errors
   (SQLSTATE 40001-class) in logs; `SHOW ENGINE INNODB STATUS` shows the two
   statements. *Fix:* order all multi-resource locking (e.g. by id) — see
   [Transactions](../data/transactions.md) for the retry story.

5. **Cache lock treated as write protection.** *Root cause:* "we hold the lock, so
   the DB is safe." Expiry or an uncooperative path writes anyway. *Detection:*
   invariant violations (double rows) despite lock coverage. *Fix:* unique index /
   row lock as the guarantee; cache lock demoted to coordination.

6. **Stuck schedule mutex after cache flush or Redis restart.** *Root cause:* the
   scheduler mutex (or `withoutOverlapping` key) survived as a key with no TTL, or
   was re-created in a partial state. *Detection:* cron silently skipping runs.
   *Fix:* runbook to delete the mutex key; ensure `expireAfter`/TTL is set on every
   overlap guard.

## How to test it

- **Reproduce the interleave deterministically.** Two DB connections against the same
  database, one acting as "worker A" pausing between check and write while "worker B"
  completes — assert B's write doesn't corrupt A's outcome. The ⚙️ harness
  (`examples/tests/LockingExamplesTest.php`) encodes this for the row-lock and
  unique-index cases.
- **TTL expiry:** `Carbon::setTestNow()` advance past the lock TTL, then assert a
  second acquire succeeds and the first holder's `release()` no-ops (owner check).
- **Store contract test:** a test that asserts `config('cache.default')` is a shared
  store in the production config — this catches the array-store mutex failure class
  permanently.
- **Negative test for scope:** fire two jobs for *different* resources and assert
  both ran concurrently (proves the key isn't over-broad), and two for the *same*
  resource asserting serialization (proves it isn't vacuous).

## How to operate it

- **Redis lock keys:** `redis-cli --scan --pattern 'laravel_cache_lock:*'` (prefix
  depends on config) with `TTL` per key — long-lived lock keys with small TTLs are
  normal; lock keys with no TTL are a bug.
- **Metrics:** rate of `LockTimeoutException`/block-timeouts (a contention signal),
  duplicate-execution counters from your idempotency layer, lock-wait timeouts from
  the database.
- **3am runbook:** duplicate execution → check cache store config → check mutex keys
  and their TTLs → check whether the job's duration exceeds `expireAfter`. Deadlocks
  → `SHOW ENGINE INNODB STATUS`, identify the two statements, file the ordering fix.

## How it scales

- **Row locks serialize on the row.** A hot SKU or counter row caps throughput at
  roughly "one update per row-lock round trip". Atomic single-row updates, then
  shard the counter (N buckets, sum on read), then move the hot path to a queue +
  eventual decrement.
- **Cache locks scale to Redis.** Watch Redis CPU and connection counts; lock
  contention (block() queues) is application-visible load. Beyond that, the answer
  is almost always "reduce the critical section", not "more locks".
- **Optimistic retries scale until conflicts are common.** Track retry rate; when it
  exceeds a few percent on a hot resource, switch that resource to pessimistic or
  atomic patterns.
- **Coordination is not correctness at any scale.** The unique index keeps working at
  10× load; the discipline of scope keys is what needs reviewing as the number of
  mutators grows.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `Cache::lock()` on redis/memcached/array stores | ✅ | ✅ | ✅ | ✅ |
| `lockForUpdate()` / `sharedLock()` | ✅ | ✅ | ✅ | ✅ |
| Job `WithoutOverlapping` middleware / scheduler `withoutOverlapping()` | ✅ | ✅ | ✅ | ✅ |
| `ShouldBeUnique` (dispatch-time dedupe via cache lock) | ✅ | ✅ | ✅ | ✅ |
| Atomic-lock support on `database`/`file` cache stores | ⚠️ | ⚠️ | ⚠️ | ⚠️ |

The ⚠️ row is deliberately unspecific: atomic-lock support outside redis/memcached is
a store-capability question, not a Laravel-version guarantee — check the cache docs
for *your* version and treat redis/memcached as the dependable choice regardless.
Core lock semantics above are stable across the whole range. Laravel 13's only
lock-relevant delta is the PHP 8.3 minimum.

## Related

- [Transactions](../data/transactions.md) — the boundary `lockForUpdate()` lives
  inside; savepoints and deadlock retries.
- [Queues](../messaging/queues.md) — where `withoutOverlapping` and unique jobs
  meet workers; the retry_after/timeout interplay.
- [Caching](../data/caching.md) — cache locks ride on the cache store; stampede
  protection is locking applied to cache rebuilds.
- [Idempotency](../messaging/idempotency.md) — the alternative to exclusion: make
  duplicates harmless instead of impossible.
- Covered since this line was written: [Redis as infrastructure](redis.md) and
  [observability](../quality/observability.md).
