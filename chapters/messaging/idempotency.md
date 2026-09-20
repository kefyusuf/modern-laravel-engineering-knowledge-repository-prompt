---
title: Idempotency
topic: idempotency
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/IdempotencyExamplesTest.php
---

# Idempotency

Idempotency means: applying the same operation twice has the same effect as applying
it once. You do not get to choose whether retries happen — networks retry, users
double-click, brokers redeliver — you only choose whether retries are safe. This
chapter is about making them safe deliberately: natural idempotency where possible,
key-based dedupe where not, and the storage rules that keep the guarantee under
concurrency.

## TL;DR

- **Use it when:** the operation is reachable twice — queue consumers (at-least-once
  delivery), webhook receivers, HTTP endpoints with client retries, overlapping cron
  jobs.
- **Don't use it when:** the operation is already atomic and repeatable (a single
  `UPDATE ... WHERE` guard, a PUT by natural key) — an extra dedupe layer is cost
  without value.
- **The classic production incident:** dedupe implemented in the cache with a short
  TTL, expiring before the *real* retry arrives (hours later, from a dead-letter
  requeue or a partner's nightly replay). The second: check-then-insert without a
  unique index — two concurrent retries both pass the check, both insert, the
  customer is charged twice.

## Problem

The delivery semantics of every boundary you own:

| Boundary | Reality |
|---|---|
| HTTP requests | Clients retry; proxies retry; users double-click |
| Queue consumers | At-least-once delivery; visibility-timeout duplicates; deployment-time requeues |
| Webhooks | Senders deliver at-least-once by design; no ordering guarantee |
| Cron/schedules | Overlapping runs when the previous run overruns |

None of these deliver "exactly once" by themselves. "Exactly once" is achieved by
at-least-once delivery **plus** idempotent processing. When that's missing, the bug
class is double effects: two charges, two shipments, two emails, decremented twice.

## When to use it

- **Queue consumers.** Any job whose handle() does more than write an idempotent
  single statement. Retries are the queue's feature — see
  [Queues](queues.md).
- **Webhook receivers.** Assume duplicates and replays as normal weather. Key on the
  sender's event id.
- **POST endpoints that create money-or-inventory-like state.** Payments, orders,
  subscriptions — the client may retry after a timeout while the first request
  succeeded server-side.
- **Overlapping scheduled work.** Nightly jobs that might overrun into the next
  tick ([Locking](../data/locking.md) prevents overlap; idempotency survives the
  case where prevention fails).

## When NOT to use it

- **Naturally idempotent operations.** `UPDATE accounts SET balance = balance - 100
  WHERE id = ? AND balance >= 100` run twice? The second run finds the balance too
  low and does nothing (or decrements twice if that's the semantics — then it *isn't*
  idempotent and needs a key). PUT to a fixed URI replacing a resource: idempotent by
  design.
- **Reads.** GETs don't need dedupe; they need caching discipline at most
  ([Caching](../data/caching.md)).
- **Harmless duplicates.** An extra "order confirmed" analytics event is noise, not
  a bug — don't build machinery for it.
- **As a substitute for locking.** Dedupe prevents *repeats*; it doesn't prevent
  *concurrent* distinct operations racing each other. Different problem — see
  [Locking](../data/locking.md).

## The layers (read this before writing code)

Ordered from strongest to most situational — always take the highest layer that
applies:

1. **Natural idempotency.** The operation targets a natural key with replace-or-
   conditional semantics. No extra infrastructure. *Prefer this everywhere it fits.*
2. **Atomic conditional writes.** One statement whose `WHERE` clause encodes the
   precondition (`WHERE status = 'pending'`, `WHERE balance >= n`). The database
   enforces "at most once".
3. **Unique-index-backed dedupe.** A processed-messages / idempotency-keys table
   with a unique index, written **in the same transaction** as the effect. Works
   when the effect is multi-statement. *This is the workhorse.*
4. **Cache-based dedupe.** Fast, TTL-bounded, lossy — only ever a fast path in
   front of layer 3, never the guarantee.

## The simplest correct implementation

Layer 3 in its minimal form — a unique index and one transaction:

<!-- example: idempotency/dedupe-unique-index -->
```php
<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;

final class ApplyPointsGrant
{
    public function execute(string $source, string $messageKey, int $userId, int $points): void
    {
        DB::transaction(function () use ($source, $messageKey, $userId, $points) {
            // The unique index is the guarantee: under any concurrency,
            // exactly one insert wins. insertOrIgnore turns "already
            // processed" into a no-op instead of an exception.
            $claimed = DB::table('processed_messages')->insertOrIgnore([
                'source' => $source,          // e.g. 'loyalty-webhook'
                'message_key' => $messageKey, // the sender's event id
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return; // already applied — this is a duplicate delivery
            }

            // Effect + dedupe row commit ATOMICALLY. There is no window
            // where "deduped but not applied" or the reverse.
            DB::table('users')->where('id', $userId)->increment('points', $points);
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
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('message_key');
            $table->timestamp('created_at');
            $table->unique(['source', 'message_key']); // THE guarantee
        });
    }
};
```

Why the index and not a `->whereKey()->exists()` check: the check-then-insert is
itself a check-then-act race. Two concurrent deliveries both find no row, both
insert, both apply. The unique index makes the second insert impossible — the
database is the arbiter, not the application's timing.

## What production requires

### Full consumer flow (webhooks, queue events)

<!-- example: idempotency/consumer-dedupe -->
```php
<?php

namespace App\Actions;

use App\Models\ProcessedMessage;
use Illuminate\Support\Facades\DB;

final class HandlePaymentSucceeded
{
    public function execute(array $event): void
    {
        // Key extraction: the SENDER's identity for this event — never
        // generated per attempt, or every retry becomes a "new" event.
        $source = 'stripe';
        $messageKey = (string) $event['id'];

        DB::transaction(function () use ($source, $messageKey, $event) {
            $claimed = DB::table('processed_messages')->insertOrIgnore([
                'source' => $source,
                'message_key' => $messageKey,
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return; // duplicate delivery — acknowledge and move on
            }

            $subscription = DB::table('subscriptions')
                ->where('external_id', $event['data']['object']['subscription'])
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                throw new \RuntimeException('Unknown subscription: webhook out of order?');
            }

            DB::table('subscriptions')
                ->where('id', $subscription->id)
                ->update(['status' => 'active', 'paid_until' => $event['paid_until']]);
        });
    }
}
```

Production additions over the minimal version: the dedupe row carries the source
(multi-tenant, multi-sender scoping), out-of-order events are detected explicitly
(throw → the queue retries → the earlier event may have arrived by then), and the
whole thing is transactional with the state change.

### HTTP APIs with idempotency keys

For client-facing endpoints, the Stripe-style contract: the client generates a key
per *logical operation* (not per attempt), the server stores key + request
fingerprint + response, and replays return the stored response.

<!-- example: idempotency/api-idempotency-key -->
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class IdempotencyKey
{
    private const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next)
    {
        $key = $request->header(self::HEADER);

        if ($key === null) {
            return $next($request); // idempotency optional per route
        }

        $scope = 'orders:create';           // per-route scope
        $fingerprint = hash('sha256', $request->getMethod()."\n".$request->getContent());

        return DB::transaction(function () use ($key, $scope, $fingerprint, $request, $next) {
            // Claim the key. The unique index (scope, key) admits one row.
            $claimed = DB::table('idempotency_keys')->insertOrIgnore([
                'scope' => $scope,
                'key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => 'in_flight',
                'created_at' => now(),
            ]);

            $existing = DB::table('idempotency_keys')
                ->where('scope', $scope)->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $claimed === 0) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    // Same key, different request: a client bug — fail loudly.
                    return response()->json(['error' => 'idempotency_key_reused'], 422);
                }
                if ($existing->status === 'completed') {
                    // Replay: return the stored response.
                    return response(json_decode($existing->response_body, true))
                        ->withHeaders(['Idempotent-Replay' => 'true']);
                }
                // In-flight: first attempt is still running. Simplest honest
                // answer for a POST: conflict, client should retry later.
                return response()->json(['error' => 'request_in_progress'], 409);
            }

            $response = $next($request);

            DB::table('idempotency_keys')
                ->where('scope', $scope)->where('key', $key)
                ->update([
                    'status' => 'completed',
                    'response_body' => json_encode($response->getData(true)),
                ]);

            return $response;
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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->string('request_fingerprint');
            $table->string('status');
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at');
            $table->unique(['scope', 'key']); // one key = one operation
        });
    }
};
```

Design decisions this example encodes — each is a real production debate:

- **Scope the key** (`scope`, and in multi-tenant apps the tenant id): a raw key
  collision across tenants must not hand over another tenant's response.
- **Fingerprint the request**: same key with a different payload is a client bug —
  reject it rather than silently deduping the wrong operation.
- **In-flight handling is explicit.** Concurrent duplicate requests get a 409, not
  a race. (Alternative: hold a row lock until the first finishes — more complex,
  sometimes worth it.)
- **Retention:** keys and response bodies accumulate — prune older than the client
  retry window (typically 24h; know your clients' worst retry schedule).
- **Payloads may contain PII** — you are storing request/response bodies; apply the
  same retention and access rules as the primary data.

### Natural keys and upserts

<!-- example: idempotency/upsert-race -->
```php
<?php

namespace App\Actions;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

final class SyncCustomerFromCrm
{
    /**
     * Idempotent by natural key (crm_id): the same sync event applied
     * twice converges on the same state.
     */
    public function execute(array $crmCustomer): Customer
    {
        return DB::transaction(function () use ($crmCustomer) {
            // upsert() pushes the conflict resolution into the database:
            // safe under concurrent syncs, unlike check-then-create.
            Customer::upsert(
                [
                    [
                        'crm_id' => $crmCustomer['id'],
                        'email' => $crmCustomer['email'],
                        'name' => $crmCustomer['name'],
                        'synced_at' => now(),
                    ],
                ],
                uniqueBy: ['crm_id'],          // the natural key + its index
                update: ['email', 'name', 'synced_at'],
            );

            return Customer::query()->where('crm_id', $crmCustomer['id'])->firstOrFail();
        });
    }
}
```

The bad version this replaces: `if (! Customer::where('crm_id', $id)->exists())
Customer::create(...)` — two concurrent syncs both fail the check and both create.
`updateOrCreate`/`firstOrCreate` have the same race window unless a unique index
exists behind them and you're prepared to catch the constraint violation. With a
unique index on `crm_id`, the upsert is the whole story.

### What NOT to dedupe with

- **The cache alone.** TTL expiry, eviction, and cache churn break the guarantee
  exactly when the retry arrives late. Cache dedupe is a hot-path optimization in
  front of the index-backed dedupe, never the guarantee.
- **`ShouldBeUnique`.** It deduplicates at *dispatch* time (and only while the lock
  is alive) — it is not execution-level idempotency
  ([Queues](queues.md)).
- **"It probably won't happen twice."** It will.

## What usually goes wrong

1. **Check-then-insert race.** *Root cause:* dedupe implemented as an application
   check, not a constraint. *Detection:* duplicate rows/effects where the guard
   exists; load testing reproduces instantly. *Fix:* unique index + `insertOrIgnore`
   in the same transaction as the effect.
2. **Dedupe and effect not atomic.** *Root cause:* dedupe row committed in one
   transaction, effect in another (or in cache). A crash between them leaves
   "processed but not applied" — the retry is then *rejected* and the effect is lost.
   *Detection:* state that never converges after failures. *Fix:* one transaction;
   the dedupe insert IS the claim on the effect.
3. **Key scope wrong.** *Root cause:* keys without tenant/source scoping (collision
   across tenants suppresses a legitimate operation), or keys regenerated per retry
   (every retry looks new). *Detection:* duplicate effects with per-retry-generated
   keys; cross-tenant data in dedupe lookups. *Fix:* scope = sender + tenant +
   operation; key = the logical operation's identity, stable across attempts.
4. **Retention misses the retry window.** *Root cause:* dedupe records pruned at 1h;
   the partner replays at 6h. *Detection:* duplicates arriving with keys "not found"
   in the dedupe store. *Fix:* retention > worst-case sender retry schedule (ask,
   don't guess — 24h is a common floor).
5. **Replay returns wrong response shape.** *Root cause:* stored response without
   status code/headers, or version-sensitive body replayed after schema changes.
   *Detection:* clients parsing replayed responses incorrectly after a deploy.
   *Fix:* store status + body + a schema version; after a breaking change, retire
   rather than replay old keys.
6. **In-flight keys locking out retries forever.** *Root cause:* a key stuck in
   `in_flight` after a crashed request; every replay gets 409 forever. *Detection:*
   409 storm on one key. *Fix:* in-flight timeout (heartbeat or created_at-based
   expiry) that releases stale claims.

## How to test it

- **Replay:** apply once, capture effect; apply again with the same key; assert the
  effect count is still 1 and the outcome is the recorded one (⚙️
  `examples/tests/IdempotencyExamplesTest.php`).
- **Concurrent replay:** the deterministic two-worker interleave — both process the
  same key; exactly one effect lands. This is the test the check-then-insert version
  fails, and the reason the unique index is non-negotiable.
- **Fingerprint mismatch:** same key, different payload → assert 422, no effect.
- **Expiry/retention:** advance time past retention; assert the key may be reclaimed
  and what the new outcome is (documented behavior, not an accident).
- **Atomicity:** inject a failure between dedupe insert and effect — assert both
  rolled back together (the retry then succeeds from scratch).

## How to operate it

- **Duplicate rate as a product signal:** `dedupe hits / total deliveries` per
  source. Rising duplicates from one sender = their retry storm (or your latency);
  falling to zero for a source you *know* retries = the dedupe store is broken
  (e.g. pruning too aggressive).
- **Stuck in-flight keys:** count of `in_flight` older than your in-flight timeout —
  these are crashed-request tombstones; alert before clients notice 409s.
- **Retention job:** pruning is a scheduled job with its own monitoring; a dead
  prune job shows up as table growth, then as slow lookups.
- **3am runbook:** "double charge" → verify via the dedupe table which key was
  applied twice (scope/index columns), then check whether the two attempts bypassed
  the key path entirely (that's where the second effect came from).

## How it scales

- **Dedupe table growth** is the scaling cost. Partition by time, prune on schedule,
  and consider a narrow hot index (`scope`, `key`) — lookup is a point query; the
  table can be huge without hurting.
- **Hot keys** (a popular retried operation) serialize on one row. That's fine —
  a retried operation *should* serialize. Contention from *distinct* concurrent
  operations means your scope is too broad.
- **Two-tier dedupe** (cache fast path + index guarantee) scales the read side;
  the write path always pays the index — that's the guarantee you're buying.
- **Beyond one database:** cross-service exactly-once needs the same pattern at the
  receiving service ([outbox](outbox.md) + inbox) — the database constraint travels
  with whichever service owns the state.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `upsert()` / `insertOrIgnore()` | ✅ | ✅ | ✅ | ✅ |
| Unique indexes via migrations | ✅ | ✅ | ✅ | ✅ |
| `ShouldBeUnique` dispatch-time dedupe | ✅ | ✅ | ✅ | ✅ |
| HTTP middleware contract | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Idempotency is mostly database + HTTP semantics wearing Laravel's clothes — there is
almost no version-sensitive framework surface here, which is why the pattern is
stable to teach. Driver nuances of `insertOrIgnore` (MySQL's `INSERT IGNORE` also
swallows *other* errors; Postgres `ON CONFLICT DO NOTHING` is precise) are database
differences, not Laravel ones — know your driver.

## Related

- [The transactional outbox](outbox.md) — the producer half; its inbox consumer is
  this chapter's layer 3 applied to relayed events.
- [Queues](queues.md) — why at-least-once delivery makes this mandatory; job-level
  dedupe interplay.
- [Transactions](../data/transactions.md) — the atomicity boundary holding dedupe
  row and effect together.
- [Locking](../data/locking.md) — exclusion vs dedupe: sibling answers to different
  concurrency problems.
- [Caching](../data/caching.md) — where cache-based dedupe fits (fast path only).
- Roadmap: API design (idempotency-key header conventions, 409/422 semantics).
