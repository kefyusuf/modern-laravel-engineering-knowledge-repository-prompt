---
title: Transactions
topic: transactions
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/TransactionsExamplesTest.php
---

# Transactions

A database transaction is the boundary inside which "all of these writes happened" or
"none of them did". This chapter is about drawing that boundary in the right place —
and about the two ways Laravel applications routinely get it wrong: dispatching work
that races the commit, and holding the boundary open across calls that don't belong
inside it.

## TL;DR

- **Use it when:** several writes form one invariant (order + inventory + payment
  ledger row) and a partial result would corrupt state.
- **Don't use it when:** the "unit" spans external systems (HTTP APIs, mail, queue
  publish to a broker) — the transaction cannot cover their outcome.
- **The classic production incident:** a job dispatched *inside* an open transaction is
  picked up by a worker before the commit, finds no committed data, and fails — or
  worse, half-works. Fix: `$afterCommit` / `DB::afterCommit()`. The second classic:
  an HTTP call inside `DB::transaction()` holding row locks for the duration of a
  third-party timeout.

## Problem

The failure mode is the partial state. The charge was captured, the order row is gone
because an exception fired three lines later. The order row committed, the three
downstream systems were notified twice because the request retried. The inventory
decrement committed but the payment record didn't, so stock exists that nobody paid
for.

All of these are boundary-drawing errors. A transaction gives you atomicity only over
what the database can see — the moment your boundary includes something the database
cannot roll back (a side effect, a network call, another process's work), you have
replaced "atomic" with "usually looks atomic in staging".

## When to use it

- Multi-row invariants: an `Order` plus its `OrderLine`s plus a ledger entry must
  appear together or not at all.
- State transitions with checks: read a balance, verify, write — where the read and
  the write must be one step (or be replaced by an atomic single-statement update —
  see [Locking](../data/locking.md)).
- Writing a message to an outbox table in the same commit as the state change that
  produced it — see [The transactional outbox](../messaging/outbox.md).
- Anything a consumer downstream treats as "if it exists, it's complete".

## When NOT to use it

- **Around external I/O.** An HTTP call, a mail send, or an S3 upload inside
  `DB::transaction()` holds row locks and connection time for the full timeout window
  of the call. Move it after the commit and make the post-commit step retryable
  ([Queues](../messaging/queues.md)).
- **Single-statement atomic updates.** `UPDATE accounts SET balance = balance - 100
  WHERE id = ? AND balance >= 100` needs no explicit transaction; wrapping it in one
  adds nothing.
- **As a retry mechanism.** `DB::transaction($cb, 2)` retries deadlocks, not
  business-rule failures. Retrying non-idempotent work inside a transaction boundary
  creates double effects — pair retries with
  [idempotency](../messaging/idempotency.md).
- **To make two independent requests "consistent".** A transaction spans one
  connection, one process, one moment. Cross-request "consistency" is a domain
  modeling problem, not a transaction problem.

## The simplest correct implementation

The smallest correct unit: a closure, an exception path, and a commit you can reason
about. `DB::transaction()` begins the transaction, runs the closure, commits on return
and rolls back on any throwable, then re-throws.

```php
<?php

namespace App\Actions;

use App\Models\Inventory;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class PlaceOrder
{
    /**
     * @throws \App\Exceptions\InsufficientStock
     */
    public function execute(int $skuId, int $quantity, int $customerId): Order
    {
        return DB::transaction(function () use ($skuId, $quantity, $customerId) {
            $inventory = Inventory::query()
                ->whereKey($skuId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventory->available < $quantity) {
                throw new \App\Exceptions\InsufficientStock($skuId);
            }

            $inventory->decrement('available', $quantity);

            return Order::create([
                'customer_id' => $customerId,
                'sku_id' => $skuId,
                'quantity' => $quantity,
            ]);
        });
    }
}
```

Everything inside the closure succeeds together. The decrement and the order either
both exist afterwards or neither does. `lockForUpdate()` prevents two concurrent
requests from both passing the availability check — the full reasoning lives in
[Locking](../data/locking.md).

## What production requires

### Keep the boundary short

A transaction holds locks, connection time, and (on some engines) undo log pressure.
The boundary should cover the *writes*, nothing else.

<!-- example: transactions/short-transactions -->
```php
<?php

namespace App\Actions;

use App\Jobs\NotifyFulfillment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class PlaceOrderProduction
{
    public function execute(int $skuId, int $quantity, int $customerId): Order
    {
        // 1. The transaction covers only the state change.
        $order = DB::transaction(function () use ($skuId, $quantity, $customerId) {
            $inventory = DB::table('inventories')
                ->where('id', $skuId)
                ->lockForUpdate()
                ->first();

            if ($inventory === null || $inventory->available < $quantity) {
                throw new \App\Exceptions\InsufficientStock($skuId);
            }

            DB::table('inventories')
                ->where('id', $skuId)
                ->decrement('available', $quantity);

            return Order::create([
                'customer_id' => $customerId,
                'sku_id' => $skuId,
                'quantity' => $quantity,
            ]);
        });
        // 2. External I/O happens after the commit — and is queued, not awaited.
        // (Outside any transaction, dispatch is immediate.)
        NotifyFulfillment::dispatch($order->id);

        return $order;
    }
}
```

The HTTP/notification work that *would* have been in the transaction is now a queued
job with its own retry policy. If you must call a third-party API synchronously, do it
before the transaction starts or after it commits — never inside.

### Dispatch side effects after the commit

<!-- example: transactions/after-commit -->
```php
<?php

namespace App\Actions;

use App\Jobs\ShipOrder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class ConfirmOrder
{
    public function execute(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $order->update(['status' => 'confirmed']);

            // Wrong without ->afterCommit(): a worker can run this job before
            // the COMMIT lands, find status='pending', and mis-handle the order.
            ShipOrder::dispatch($order->id)->afterCommit();

            return $order;
        });
    }
}
```

`->afterCommit()` defers the dispatch until the surrounding transaction commits (if
there is no transaction, it dispatches immediately). The same option exists as a
`public $afterCommit = true;` property on the job, and `DB::afterCommit(callable)`
registers any callback — including event listeners — to run after the commit.

**Precisely what it does not do:** `afterCommit` is a *dispatch-order* guarantee. The
worker may still fail, the event may still be delivered twice, and the process can
still die between `COMMIT` and dispatch. When the side effect *must not* be lost, the
boundary itself is wrong — write an outbox row in the same transaction and relay it
([The transactional outbox](../messaging/outbox.md)).

### Expect deadlocks; retry them deliberately

Two transactions that lock the same two rows in opposite orders deadlock. The
database kills one; Laravel surfaces it as a `QueryException` with a deadlock SQL
state. `DB::transaction()`'s second parameter retries the *whole closure* on a
deadlock:

<!-- example: transactions/deadlock-retry -->
```php
<?php

namespace App\Actions;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class AllocateInventory
{
    public function execute(array $skuIds, int $customerId): Order
    {
        // Attempt count includes the first try. Lock rows in a consistent
        // order (sorted ids) so this retry loop is rarely needed at all.
        return DB::transaction(function () use ($skuIds, $customerId) {
            $rows = DB::table('inventories')
                ->whereIn('id', collect($skuIds)->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // ... allocation logic against $rows ...
            return Order::create(['customer_id' => $customerId]);
        }, attempts: 3);
    }
}
```

The retry re-runs your closure, so the closure must be safe to run twice — anything
non-transactional inside it (a variable incremented outside, a message appended to a
collection) will happen twice on retry. This is the retry/idempotency coupling again:
[Idempotency](../messaging/idempotency.md).

### Nesting: what actually happens

Laravel does not open a second real transaction on the same connection — nested
`DB::transaction()` calls create **savepoints**. Two consequences matter:

1. **Inner commits are not durable.** The inner "commit" only releases a savepoint.
   Nothing is durable until the outermost closure returns.
2. **An inner failure does not doom the outer transaction** *if the outer closure
   catches the exception*. The savepoint is rolled back to and execution continues;
   the outer commit persists the outer work. If nobody catches it, the whole chain
   rolls back.

Treat nesting as a composability mechanism (a service can be transactional whether or
not its caller is), not as a way to "commit part of the work". A cross-module
transaction is usually a boundary violation — see
[The modular monolith](../architecture/modular-monolith.md).

### Atomic updates beat read-then-write

<!-- example: transactions/lost-update -->
```php
<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

final class DecrementStock
{
    public function execute(int $skuId, int $quantity): bool
    {
        // One statement: check and mutate are atomic. No transaction, no lock,
        // no lost-update window between two PHP statements.
        $affected = DB::table('inventories')
            ->where('id', $skuId)
            ->where('available', '>=', $quantity)
            ->decrement('available', $quantity);

        return $affected > 0;
    }
}
```

The read-modify-write pattern (select, check in PHP, write) has a lost-update window
between the statements unless you hold a row lock across them. When the invariant
fits in one statement's `WHERE`, prefer this. When it doesn't, take the lock —
[Locking](../data/locking.md) has the decision table.

## What usually goes wrong

1. **Job dispatched inside a transaction, worker reads pre-commit state.**
   *Root cause:* the queue is a separate process; `dispatch()` during a transaction
   enqueues immediately. *Detection:* jobs that intermittently fail with "record not
   found" or act on stale status, always within milliseconds of the write.
   *Fix:* `->afterCommit()` on the dispatch (or `$afterCommit = true` on the job), or
   an outbox row if it must not be lost.

2. **HTTP call inside the boundary.**
   *Root cause:* "the API call is part of the flow, so it goes in the transaction."
   The third-party's 30-second timeout becomes 30 seconds of row locks. *Detection:*
   `innodb_trx` rows older than a few seconds; lock-wait timeouts correlating with
   third-party latency graphs. *Fix:* move the call outside; persist an intent row in
   the transaction and process the call from a job with retries.

3. **Nested-transaction misconception — "the inner one committed, so it's saved".**
   *Root cause:* savepoints aren't transactions. The inner commit evaporates when the
   outer rolls back. *Detection:* data that "was created but vanished" after a later
   failure in the same request. *Fix:* review nesting; if partial persistence is
   actually required, it must be a separate top-level transaction on a separate
   connection — and that changes the invariants, so model it explicitly.

4. **Retry-the-request double effects.**
   *Root cause:* transaction rolls back on the database, but a mail was already sent
   inside the closure (or a payment captured before it). The DB-side retry is clean;
   the side effect happens twice. *Detection:* duplicate emails/charges correlated
   with exception rates. *Fix:* side effects out of the boundary + idempotency keys
   on everything that retries — [Idempotency](../messaging/idempotency.md).

5. **Lazy loading inflating the boundary.**
   *Root cause:* `lockForUpdate()` on a header row, then lazy-loading relations in a
   loop inside the transaction — hundreds of queries while holding locks.
   *Detection:* long-running transactions in `innodb_trx` issued by request handlers
   (not reports); N+1 in query logs. *Fix:* eager-load before the transaction; touch
   the minimum inside it.

6. **Assuming transaction(Closure) retries business exceptions.**
   *Root cause:* the `$attempts` parameter retries deadlock errors only (SQL state
   40001-class errors on supported drivers). A thrown `InsufficientStock` ends the
   transaction for good. *Detection:* failed flows despite "we set retries". *Fix:*
   explicit application-level retry policy, with idempotency.

## How to test it

- **Rollback semantics:** assert the state after a thrown exception — nothing
  persisted. Use `RefreshDatabase` (re-runs migrations, true isolation) rather than
  `DatabaseTransactions` (wraps in a transaction that never commits — which hides
  commit-time behavior like `afterCommit` timing).
- **Dispatch timing:** the ⚙️ harness encodes this with the REAL database queue:
  dispatch `->afterCommit()` inside a transaction and count `jobs` rows — zero
  before the commit, one after. Rows make the ordering claim observable without
  trusting a fake's internals (`examples/tests/TransactionsExamplesTest.php`,
  [strategy](../../docs/examples.md)).
- **Lost updates:** two connections to the same in-memory database reproducing the
  interleave, asserting the atomic-update version never goes negative.
- **Deadlock retry:** hard to reproduce honestly on SQLite; covered at the unit level
  by asserting the closure is re-invoked on a mocked deadlock exception, and at the
  ops level by deadlock metrics.

## How to operate it

- **Long transactions are the thing to watch.** On MySQL: `information_schema.innodb_trx`
  (age of running transactions, trx_rows_locked); alert when any transaction exceeds
  your p95 write latency by a wide margin (start with seconds, not minutes).
- **Lock waits:** `innodb_lock_waits` / `innodb_lock_wait_timeout` tells you who is
  blocked by whom; lock-wait timeout spikes mean a boundary is too wide or an index
  is missing.
- **Deadlock volume:** MySQL's `SHOW ENGINE INNODB STATUS` and performance_schema
  deadlock counters; a deadlocking query pair appearing at N/hour is a code smell to
  file, not noise to ignore.
- **Queue-side signature:** "record not found" in job failures within ~1s of enqueue
  is the dispatch-before-commit fingerprint. Search failed_jobs for it.
- **3am runbook:** find the long transaction (`innodb_trx`), decide whether killing
  the query is safe (it rolls back — that's usually *correct*), and find the handler
  that opened it before morning.

## How it scales

The transaction boundary is a serialization point: the narrower it is, the more
concurrent writers fit. In rough order of leverage:

1. **Shrink the boundary** (move I/O out, atomic statements over RMW). Cheapest,
   biggest win.
2. **Hot rows** (counters, stock on one SKU) become the contention limit. Move to
   atomic single-row updates, then to splitting the counter (sharded buckets), then
   to accepting a queue + eventual decrement.
3. **Batch writes** in one transaction (chunked bulk inserts) rather than one
   transaction per row — but keep chunks bounded so a rollback isn't a full re-do.
4. **Read replicas** scale reads, but a transaction on the primary and reads from a
   replica see *different snapshots*; decide per flow whether read-after-write must
   hit the primary (and mark those queries as such).
5. **Cross-service transactions do not exist.** When a flow crosses a service
   boundary, the outbox + saga patterns replace two-phase commit — start at
   [The transactional outbox](../messaging/outbox.md).

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `DB::transaction(Closure, $attempts)` deadlock retry | ✅ | ✅ | ✅ | ✅ |
| Savepoint semantics for nested transactions | ✅ | ✅ | ✅ | ✅ |
| `DB::afterCommit()` / `$afterCommit` on dispatchables | ✅ (stable since Laravel 8) | ✅ | ✅ | ✅ |
| `beforeCommit` hook | ✅ (stable since Laravel 8) | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Nothing in this chapter's core semantics changed across the currently supportable
range — the behavior above is stable since Laravel 8, which is why the table carries
no caveats. Isolation-level configuration syntax is driver configuration, not
framework API, and is not covered by this table.

## Related

- [Locking](../data/locking.md) — `lockForUpdate()` inside a transaction is the read-
  then-write fix; scope and expiry rules live there.
- [The transactional outbox](../messaging/outbox.md) — when the side effect must
  not be lost, the boundary moves outward: commit the message, relay later.
- [Queues](../messaging/queues.md) — workers are the "other process" racing your
  commit; retry semantics and `$afterCommit` interplay.
- [Idempotency](../messaging/idempotency.md) — retries (deadlock, queue, request-
  level) are only safe when effects are idempotent.
- Roadmap: observability (long-transaction dashboards), testing (race simulation
  patterns).
