---
title: The transactional outbox
topic: outbox
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/OutboxExamplesTest.php
---

# The transactional outbox

When the database is the source of truth and some side effect must not be lost, the
outbox pattern closes the gap: write the message into an outbox table *inside the
same transaction* as the state change, then relay it to the queue asynchronously.
The relay is at-least-once; consumer dedupe makes the end-to-end result effectively
exactly-once. This chapter includes a complete reference implementation.

## TL;DR

- **Use it when:** an event other systems depend on must survive exactly with the
  state change that caused it — integration events, cross-module events, webhook
  fan-out, audit-relevant notifications.
- **Don't use it when:** the side effect may be lost without harm (plain
  `afterCommit` dispatch suffices), or you don't own a database transaction around
  the state change.
- **The classic production incident:** "we dispatched the job, then the transaction
  rolled back" — the consumer acted on an event for state that never existed. Or the
  mirror: "we committed, then the process died before dispatch" — an event that
  never existed at all. Both are the dual-write problem; `afterCommit` fixes only
  the first.

## Problem

Two systems cannot commit atomically: your database and your message broker. Every
ordering of "write state" and "publish event" has a failure mode:

- **Publish, then write.** The publish succeeds, the transaction rolls back —
  consumers act on state that doesn't exist.
- **Write, then publish.** The commit succeeds, the process dies before publish —
  the event is lost forever.
- **Dispatch inside the transaction** (Laravel's `dispatch()` in a transaction
  enqueues immediately — the job hits a worker *before* your commit): the worker
  reads uncommitted, then committed-not-yet, state.

`DB::afterCommit()` / `->afterCommit()` eliminates the third mode and narrows the
second to a crash window (commit lands, dispatch doesn't). If that window is
acceptable — a lost "send welcome email" is survivable — stop there
([Transactions](../data/transactions.md)). If the event *must not* be lost, the
commit itself has to carry the message: that's the outbox.

## When to use it

- Events downstream systems process: "OrderShipped" must reach fulfillment exactly
  in company with the order's state change.
- Cross-module integration events inside a monolith
  ([modular monolith](../architecture/modular-monolith.md)) — the outbox is how a
  module publishes without cross-module transactions.
- Webhook fan-out where a missed webhook is a contract violation.
- Notifications whose loss is a compliance problem, not an annoyance.

## When NOT to use it

- **Lossy-safe side effects.** If the welcome email may vanish on a crash window,
  `afterCommit` is cheaper in table growth, relay ops, and latency.
- **Same-database consumers.** If the "consumer" just queries the same database,
  publish nothing — they read the state.
- **Very high-frequency telemetry.** Rows-per-write is a tax; an event streaming
  path (or sampling) fits better than a per-write outbox row.
- **You don't control the consumer.** The outbox contract includes consumer-side
  dedupe ([Idempotency](idempotency.md)); if the consumer won't dedupe, you're
  delivering duplicates into their logic without their knowledge — agree first.

## The reliability spectrum

Place every "must the event arrive?" decision on this ladder — buy the cheapest rung
that satisfies the requirement:

1. **Fire-and-forget** (`dispatch()` anywhere): fastest, both failure modes live.
2. **`afterCommit` dispatch**: no phantom events; crash window can lose events.
3. **Outbox + relay**: no phantom, no lost events; at-least-once delivery (duplicates
   possible); latency = relay cadence.
4. **Outbox + relay + consumer inbox (this chapter, complete)**: effectively
   exactly-once; the standard for correctness-critical integration.
5. **CDC-based outbox** (Debezium-class tooling reading the transaction log): the
   same pattern at high scale, relay replaced by log tailing — the next step when
   polling the outbox table becomes the bottleneck; a different ops discipline, not
   a different correctness model.

## The simplest correct implementation

The outbox IS the minimal correct implementation — there's no simpler correct
version, only simpler incorrect ones. The bad version first, untagged:

```php
<?php

// BAD: the event can be consumed before commit (phantom), or lost on a
// crash between COMMIT and dispatch (lost), or consumed for a transaction
// that rolls back (phantom again).
public function ship(Order $order): void
{
    DB::transaction(function () use ($order) {
        $order->update(['status' => 'shipped']);
        OrderShipped::dispatch($order->id); // no afterCommit, no durability
    });
}
```

The production-safe core, in three parts.

**Part 1 — schema and model:**

<!-- example: outbox/outbox-table -->
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');          // your contract: 'order.shipped'
            $table->json('payload');               // YOUR schema, versioned (see below)
            $table->unsignedBigInteger('aggregate_id'); // e.g. order id — for ordering
            $table->timestamp('created_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // The relay's working set: pending rows, oldest first.
            $table->index(['dispatched_at', 'id']);
        });
    }
};
```

```php
<?php

namespace App\Outbox;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $event_type
 * @property array $payload
 * @property int $aggregate_id
 * @property \Carbon\CarbonInterface|null $dispatched_at
 */
class OutboxMessage extends Model
{
    protected $fillable = ['event_type', 'payload', 'aggregate_id'];
    protected $casts = ['payload' => 'array'];

    public function scopePending($query)
    {
        return $query->whereNull('dispatched_at')->orderBy('id');
    }
}
```

```php
<?php

namespace App\Outbox;

use Illuminate\Support\Facades\DB;

/**
 * The only door into the outbox. Call it INSIDE the transaction that makes
 * the state change — the insert commits (or rolls back) with that state.
 */
final class Outbox
{
    public function publish(string $eventType, int $aggregateId, array $payload): void
    {
        // If no transaction is open this would commit standalone — which
        // defeats the pattern; guard against accidental misuse.
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Outbox::publish() must be called inside a transaction.');
        }

        OutboxMessage::create([
            'event_type' => $eventType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
        ]);
    }
}
```

**Part 2 — usage at the write site:**

```php
<?php

namespace App\Actions;

use App\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

final class ShipOrder
{
    public function __construct(private readonly Outbox $outbox) {}

    public function execute(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();

            if ($order === null || $order->status === 'shipped') {
                return;
            }

            DB::table('orders')->where('id', $orderId)->update(['status' => 'shipped']);

            // Commits atomically with the status change. No dispatch here.
            $this->outbox->publish('order.shipped', $orderId, [
                'schema_version' => 1,
                'order_id' => $orderId,
                'shipped_at' => now()->toIso8601String(),
            ]);
        });
    }
}
```

**Part 3 — the relay:**

<!-- example: outbox/relay-claim -->
```php
<?php

namespace App\Outbox;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RelayOutbox extends Command
{
    protected $signature = 'relay:outbox {--limit=100}';

    protected $description = 'Relay pending outbox messages to the queue';

    public function handle(): int
    {
        // One transaction per batch: claim, dispatch to the real queue, mark,
        // commit. Row locks live until the commit, so concurrent relays never
        // claim the same rows (SKIP LOCKED: MySQL 8.0+/MariaDB 10.6+/
        // Postgres 9.5+; SQLite does NOT support it — single relay there).
        // Crash before commit => rows stay pending => relayed again later
        // (at-least-once). Crash after commit => job already queued.
        DB::transaction(function () {
            $claimed = OutboxMessage::query()
                ->pending()
                ->limit((int) $this->option('limit'))
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($claimed as $message) {
                // The queue job carries ONLY the reference; its handler
                // forwards to the real consumer. Enqueueing inside this
                // transaction is the deliberate trade discussed below.
                \App\Jobs\DeliverOutboxMessage::dispatch($message->id);
            }

            OutboxMessage::query()
                ->whereIn('id', $claimed->pluck('id'))
                ->update(['dispatched_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
        });

        return self::SUCCESS;
    }
}
```

Wait — the claim and the mark in one transaction with the *dispatch* between them
introduces the crash-window again (dispatch enqueued, mark not committed → the row
is relayed twice). That is **correct and intended**: relay semantics are
at-least-once, and duplicates are absorbed by the consumer inbox. Never optimize the
duplicates away on the producer side — that's how lost events come back. The honest
trade: duplicate deliveries (cheap, absorbed) vs lost events (unrecoverable).

**Part 4 — the consumer inbox:**

<!-- example: outbox/inbox-consumer -->
```php
<?php

namespace App\Jobs;

use App\Outbox\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class DeliverOutboxMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $outboxMessageId) {}

    public function handle(): void
    {
        $message = OutboxMessage::query()->find($this->outboxMessageId);

        // Pruned or externally dispatched — nothing to do.
        if ($message === null || $message->dispatched_at === null) {
            return;
        }

        // The consumer's dedupe key: the outbox message id is globally unique
        // and stable. The consumer's handler runs its own transaction with
        // its own processed_messages table (see Idempotency chapter).
        $claimed = DB::table('processed_messages')->insertOrIgnore([
            'source' => 'outbox',
            'message_key' => (string) $message->id,
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return; // duplicate delivery — absorbed by the inbox
        }

        $this->dispatchToHandler($message);
    }

    private function dispatchToHandler(OutboxMessage $message): void
    {
        $handler = match ($message->event_type) {
            'order.shipped' => new \App\Handlers\OrderShippedHandler(),
            default => throw new \RuntimeException("Unknown event type {$message->event_type}"),
        };

        $handler->handle($message->event_type, $message->payload);
    }
}
```

Producer: at-least-once. Consumer: dedupe. End to end: effectively exactly-once.

### The payload contract

The outbox payload is an **integration contract**, not a database dump:

- Define your own JSON schema per event type, with a `schema_version` field.
- Store ids and values, never serialized Eloquent models — consumers must not depend
  on your table structure.
- Changing the payload = publishing a new schema version and running both until
  consumers migrate. The outbox table is an API; treat it like one.

### Ordering

Multiple relays, or a relay + queue workers, will reorder deliveries. If consumers
need per-aggregate order ("status updates for order 42 arrive in order"), either
relay single-threaded ordered by id within an aggregate, or include a per-aggregate
sequence number in the payload and let consumers buffer/detect gaps. Do not assume
FIFO end to end — you will not get it.

## What production requires

- **Relay scheduling:** the relay is a scheduled command (its cadence bounds your
  event latency: every minute = up to a minute of lag). For lower latency, a
  dedicated worker running the relay on a tight loop — same code, different runner.
- **Retention:** dispatched rows are garbage after consumer-side retention covers
  them; a scheduled prune keeps the table a queue, not an archive.
- **Poison handling:** a message whose handler always throws must not wedge the
  relay. Track attempts; after a threshold, mark and quarantine for human review
  (the schema's `attempts` column is the bookkeeping).
- **Monitoring hooks:** relay lag (see [operate](#how-to-operate-it)) is the metric
  the whole design stands on.
- **Failure drill:** the crash-window test (below) is not optional — it IS the
  pattern's value; if you haven't reproduced it, you haven't verified the outbox.

## What usually goes wrong

1. **Publish outside the transaction.** *Root cause:* someone calls
   `Outbox::publish()` after `DB::transaction()` returns. The insert commits alone;
   a crash before the *state* transaction commits means an event for state that
   doesn't exist — the phantom again. *Detection:* events whose payload disagrees
   with database state. *Fix:* the `transactionLevel() === 0` guard in `publish()`
   makes this a loud error.
2. **Relay duplicates surprise everyone.** *Root cause:* at-least-once semantics
   treated as a bug — someone adds "mark dispatched first, then dispatch" to stop
   duplicates. Now the crash window silently *loses* events. *Detection:* review —
   the "fix" looks reasonable. *Fix:* duplicates are the design; the consumer inbox
   absorbs them. Document it in the code.
3. **SKIP LOCKED assumed everywhere.** *Root cause:* SQLite dev environment (and
   older MySQL/MariaDB) reject or ignore `SKIP LOCKED`; syntax errors in dev, or a
   full-table lock in prod-adjacent engines. *Detection:* SQL errors on relay in
   some environments only. *Fix:* feature-detect the capability; single-relay mode
   where unsupported (the harness runs SQLite and does exactly this).
4. **Outbox table becomes the new hot spot.** *Root cause:* every write now also
   writes + later updates a row; under heavy write load the index churn dominates.
   *Detection:* write latency growth correlated with outbox volume; relay lag
   creeping. *Fix:* narrow index, partitioned/pruned table, batch mark-update, and
   eventually CDC (spectrum rung 5).
5. **Payload drift.** *Root cause:* producer adds/renames a payload field without a
   version bump; consumers break on missing keys. *Detection:* consumer-side
   "undefined index" after deploys. *Fix:* schema_version + contract tests on the
   payload shape in the producer's test suite.
6. **Relay starsves under long claim transactions.** *Root cause:* claiming too many
   rows, or holding the claim transaction open while dispatching (dispatch should
   be *after* the claim transaction closes). *Detection:* lock-wait errors on
   outbox rows; relay lag variance. *Fix:* bounded batches; dispatch after commit
   (as the reference implementation does).

## How to test it

The ⚙️ harness (`examples/tests/OutboxExamplesTest.php`) encodes the pattern's
actual claims:

- **Commit coupling:** a write that throws after `publish()` → the outbox row does
  NOT exist (rolled back with the state change).
- **Relay dispatches once:** pending row + relay → queue received exactly one
  delivery, row marked dispatched.
- **Crash window:** pending row, relay runs, then relay runs AGAIN (simulating the
  mark-crash) → consumer received it twice — and the inbox dedupe absorbs it.
- **Ordering bookkeeping:** relay picks rows in id order for the same aggregate.

Beyond the harness, the consumer side inherits the
[idempotency](idempotency.md) tests (concurrent replay, atomicity).

## How to operate it

- **Relay lag is THE metric:** `now() - min(created_at) WHERE dispatched_at IS NULL`.
  Alert when it exceeds your documented freshness bound (cadence + processing time).
- **Pending count** (depth) and **oldest pending age** are the two numbers on the
  dashboard; a rising count with flat age = new event types; rising age = relay
  trouble.
- **Poison quarantine:** count of rows over the attempt threshold; these need
  humans, and they're the reason relay lag can't self-heal.
- **3am runbook:** relay lag spike → is the scheduler alive (the relay is a command
  — check its schedule), is the queue healthy (relay depends on the queue it
  dispatches to), lock contention on outbox rows (two relays with giant batches).
  Duplicate-event reports → consumer inbox missing? That's the incident to file,
  the producer is behaving as designed.

## How it scales

- **The outbox row is a per-write tax** (one insert, one later update). At moderate
  scale that's noise; at high write volume it's a second hotspot next to your
  business table. Narrow index, prune aggressively, batch the mark-update.
- **Relay parallelism** scales dispatch throughput; SKIP LOCKED claims make
  concurrent relays safe. Per-aggregate ordering requirements cap parallelism —
  order only what needs ordering (per-aggregate sequence numbers beat global
  serialization).
- **CDC (rung 5)** removes the polling loop and the mark-update; the transaction
  log becomes the relay. Correctness model unchanged: dedupe stays in the consumer.
- **Multi-region** changes the failure envelope (the outbox commits in one region's
  database); replication lag becomes another dedupe-relevant delay. Design the
  consumer inbox to be the last line of defense — it's the only piece that sees
  every duplicate.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `DB::transaction` + savepoints, `afterCommit` | ✅ | ✅ | ✅ | ✅ |
| `->lock('FOR UPDATE SKIP LOCKED')` raw clause | ✅ | ✅ | ✅ | ✅ |
| Scheduled commands running the relay | ✅ | ✅ | ✅ | ✅ |
| First-class outbox in Laravel core | ❌ (hand-built) | ❌ | ❌ | ❌ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

SKIP LOCKED is a *database* capability (MySQL 8.0+/MariaDB 10.6+/Postgres 9.5+;
SQLite lacks it), not a Laravel version matter — Laravel passes the clause through
on every version above. There is no first-class outbox in any Laravel release up to
13; packages exist but the pattern is ~200 lines to own.

## Related

- [Queues](queues.md) — the relay's destination; worker semantics, retries, and the
  `retry_after` budget the relay inherits.
- [Idempotency](idempotency.md) — the inbox is consumer-side idempotency; the
  unique-index rules live there.
- [Transactions](../data/transactions.md) — the boundary the outbox extends; the
  `afterCommit` rung this pattern supersedes.
- [The modular monolith](../architecture/modular-monolith.md) — cross-module
  events are the prime in-monolith use case.
- [Caching](../data/caching.md) — read-model invalidation is a common outbox
  consumer ([CQRS](../architecture/cqrs.md) for the fuller picture).
