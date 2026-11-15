---
title: Event-driven architecture
topic: eda
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
---

# Event-driven architecture

Event-driven architecture means modules communicate by **announcing facts**
("order 42 shipped") instead of **calling each other's procedures**. Producers
stop knowing who consumes; the coupling becomes a contract instead of a call.
It trades immediate consistency and a readable call stack for decoupling and
independence — a trade worth making only at specific boundaries, for specific
reasons. This chapter is the decision framework, built on the machinery
[the outbox](../messaging/outbox.md) and [CQRS](cqrs.md) already established.

## TL;DR

- **Use it when:** a fact genuinely has multiple independent consumers, or the
  consumer belongs to another module/bounded context you must not call into
  ([modular monolith](modular-monolith.md)).
- **Don't use it when:** the next step of a flow needs the outcome — a call
  chain is a better architecture than an event chain you then have to
  orchestrate back together.
- **The classic production incident:** the "simple notification" event that
  grew consumers silently — five listeners later, one synchronous handler
  makes every checkout pay for it, and nobody knows who subscribed.

## Problem

Call-based integration (`OrderService->notifyFulfillment()`) couples the
producer to every consumer: adding a consumer means editing the producer; the
producer's latency includes the consumers'; and a failure in a *notification*
fails the *business operation*. Event-driven integration inverts it: the
producer commits a fact and moves on; consumers react independently, on their
own schedule, with their own failure handling.

The price: the flow no longer exists as a readable sequence. "What happens
when an order ships?" becomes "who listens to OrderShipped?" — answered by
searching, not reading. And consistency becomes eventual
([CQRS](cqrs.md)'s consistency window).

## When to use it

- **Cross-module integration inside a monolith** — the module boundary rule
  ([modular monolith](modular-monolith.md)): Billing announces
  `invoice.paid`; Collections reacts without importing Billing internals.
- **Genuinely multi-consumer facts** — one fact, ≥2 independent reactions
  (fulfillment + email + analytics), each with independent failure handling.
- **Decoupling deployment ownership** — the producer team cannot see or
  schedule every consumer's work.

## When NOT to use it

- **Sequential flows.** If step B needs step A's *result* to proceed, that's
  an orchestration (a use case calling steps — [boundaries](boundaries.md)),
  not an event. Event-chaining sequential steps produces a distributed call
  stack nobody can debug.
- **One producer, one consumer, always.** That's a function call with extra
  infrastructure — write the call.
- **Request/response semantics.** "Fire this event and wait for the answer"
  is RPC wearing a costume ([Queues](../messaging/queues.md) "don't fake sync
  with async" applies).
- **As the first tool.** Events are the layer *above* transactions, outbox,
  and read models — introduce them when a concrete integration hurts, not for
  the architecture diagram.

## The two styles

| Style | Shape | Choose when |
|---|---|---|
| **Choreography** | Each consumer reacts to facts independently; no coordinator | Few steps, no shared state, reaction order doesn't matter |
| **Orchestration** | A process (use case, saga) invokes steps and compensates failures | Multi-step flows with ordering/state requirements — refunds after failed capture, etc. |

Choreography scales in *producers* (publish and forget) but collapses in
*processes*: "place order" choreographed across five modules is five facts and
no owner. Orchestration keeps flows readable by putting one module in charge —
usually the right answer for anything spanning 3+ steps; its events become
*step results*, not *flow glue*.

## The event contract

An event is a **published fact with a stable schema** — the same discipline as
the [outbox](../messaging/outbox.md) payload:

- **Name facts in past tense, owned by the producing module:**
  `App\Modules\Orders\Events\OrderShipped` — consumers subscribe to the
  producer's contract, never import its internals.
- **Payload: ids and values, versioned** (`schema_version`), never serialized
  models ([outbox](../messaging/outbox.md) contract section applies verbatim).
- **Delivery guarantees are explicit:** in-process Laravel listeners run
  inline in the request — coupled to its latency and failures; queued
  listeners are at-least-once ([Queues](../messaging/queues.md)); cross-process
  reliability requires the outbox — **there is no event-driven architecture
  without deciding how events survive a crash**.

## The reliability ladder

Every event publication sits somewhere on this ladder — the same spectrum
[the outbox chapter](../messaging/outbox.md) defines; it is repeated here
because event-driven decisions start from it:

1. **Fire-and-forget** (`dispatch()` anywhere) — fastest; both failure modes
   (phantom events, lost events) live.
2. **`afterCommit` dispatch** — no phantom events; the crash window can lose
   events.
3. **Outbox + relay** — no phantom, no lost events; at-least-once (duplicates
   possible); latency = relay cadence.
4. **Outbox + relay + consumer inbox** — effectively exactly-once; the
   standard for correctness-critical integration.
5. **CDC-based outbox** (Debezium-class tooling) — the same correctness model
   at broker scale; different ops.

For in-process listeners the rungs collapse: a synchronous listener is rung 1
(dies with the transaction — fine for same-database projections you control:
[CQRS](cqrs.md) level 2), a queued listener is rung 2
(`$afterCommit` — survives the request, not the crash window). Choosing below
the requirement is how events get lost; choosing above it everywhere is how
systems drown in infrastructure. Decide per event type.

## The simplest correct implementation

The minimal honest configuration — a queued listener behind a committed fact:

```php
<?php

namespace App\Modules\Orders\Events;

/**
 * The producer's contract: a stable, versioned fact. No Eloquent models —
 * ids and values only (outbox payload discipline).
 */
final class OrderShipped
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $merchantId,
        public readonly string $shippedAt,
        public readonly int $schemaVersion = 1,
    ) {}
}
```

```php
<?php

namespace App\Modules\Fulfillment\Listeners;

use App\Modules\Orders\Events\OrderShipped;
use Illuminate\Contracts\Queue\ShouldQueue;

final class CreateFulfillmentRequest implements ShouldQueue
{
    /**
     * Queued: the reaction happens off the request path, with the queue's
     * retry/failed-job semantics. Idempotent by construction (the consumer
     * dedupes on order id — Idempotency chapter), because delivery is
     * at-least-once.
     */
    public function handle(OrderShipped $event): void
    {
        \App\Modules\Fulfillment\Actions\OpenRequest::execute(
            orderId: $event->orderId,
            merchantId: $event->merchantId,
        );
    }
}
```

Producer side, the fact is published **reliably or knowingly-not** — from
inside the shipping use case (the outbox insert commits with the state
change; the relay delivers the event — [outbox](../messaging/outbox.md)):

```php
<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Events\OrderShipped;
use App\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

final class ShipOrder
{
    public function __construct(private readonly Outbox $outbox) {}

    public function execute(int $orderId, int $merchantId): void
    {
        DB::transaction(function () use ($orderId, $merchantId) {
            // ... mark the order shipped (the state change) ...

            // The outbox row commits WITH the state change; the relay
            // delivers the fact to consumers (at-least-once).
            $this->outbox->publish('order.shipped', $orderId, [
                'schema_version' => 1,
                'order_id' => $orderId,
                'merchant_id' => $merchantId,
                'shipped_at' => now()->toIso8601String(),
            ]);
        });
    }
}
```

## What production requires

- **Correlation across hops:** every fact carries (or the relay attaches) the
  originating `request_id`/`tenant_id` —
  [observability](../quality/observability.md)'s context propagation is what
  makes an event chain debuggable at all.
- **Consumer independence:** a consumer failing must not fail the producer —
  queued listeners + failed-job alerting per consumer
  ([Queues](../messaging/queues.md)); the producer's metrics never include
  consumer health.
- **The listener inventory as a contract:** for every published event, a
  documented list of consumers (the module README
  ([modular monolith](modular-monolith.md))). The "silent consumers" incident
  starts when this list is folklore.
- **Idempotent consumers, always** ([Idempotency](../messaging/idempotency.md))
  — at-least-once delivery is the floor for any async event path.
- **Consistency UX:** if a consumer's output is user-visible, the staleness
  window is a product decision with a bound and a metric
  ([CQRS](cqrs.md) projection lag).

## What usually goes wrong

1. **The synchronous listener tax.** *Root cause:* listeners not queued;
   every dispatch executes every handler inline — the "event decoupling" is
   fiction, latency and failure still couple. *Detection:* request latency
   includes consumer work; one listener's exception fails the producer
   (unless wrapped — which then hides it, worse). *Fix:* `ShouldQueue` on
   every listener that isn't same-transaction by design; producer
   transaction rules unchanged ([Transactions](../data/transactions.md)).
2. **The phantom event.** *Root cause:* dispatched inside a transaction
   without afterCommit/outbox — consumers react to uncommitted state.
   *Detection:* consumer "record not found" bursts right after producer
   commits. *Fix:* the reliability ladder — pick the rung deliberately.
3. **Event storms as integration creep.** *Root cause:* every new integration
   becomes one more listener on one more event; nobody owns the graph.
   *Detection:* listener counts per event growing unbounded; "what listens to
   X?" unanswerable. *Fix:* the inventory contract + a bias for orchestration
   in multi-step flows.
4. **Bi-temporal confusion.** *Root cause:* events named like commands
   (`ShipOrder` — a request) vs facts (`OrderShipped` — something that
   happened). Consumers then "reject" facts — categories break. *Detection:*
   handlers that throw "invalid state" on facts. *Fix:* naming discipline
   (past tense); commands go through use cases, not events.
5. **Payload drift breaking consumers.** *Root cause:* producer reshapes the
   event without versioning. *Detection:* consumer-side missing-key errors
   after producer deploys. *Fix:* `schema_version` + contract tests on the
   producer side ([outbox](../messaging/outbox.md) payload contract).
6. **Lost events at the crash window.** *Root cause:* rung-2 publication
   (afterCommit dispatch) where the requirement was rung 3. *Detection:*
   "the customer never got the email and there's no trace." *Fix:* outbox for
   must-not-lose facts; document which events are allowed to be lost.

## How to test it

- **Contract tests on the payload:** the producer's suite asserts the event's
  shape (keys, types, version) — consumers depend on exactly that.
- **Consumer tests:** instantiate the listener with a fact, assert the
  reaction — including the duplicate-delivery no-op
  ([Idempotency](../messaging/idempotency.md) patterns).
- **Delivery-path tests:** the outbox/relay chain's ⚙️ claims
  ([outbox](../messaging/outbox.md)) already cover commit-coupling and
  at-least-once; EDA-specific tests are the contract + consumer layers above.
- **The inventory test:** a test (or CI check) that fails when an event class
  has listeners outside its module that aren't in the documented inventory —
  architecture tests ([modular monolith](modular-monolith.md)) extend here.

## How to operate it

- **Per-event dashboards:** published count, consumed count, **and the delta**
  — the delta is lag or loss, and it's the event system's heartbeat
  ([outbox](../messaging/outbox.md) relay lag for the durable rungs).
- **Consumer health is consumer-owned:** each listener's failure rate and
  retry depth on its own queue, alerting to its owning module.
- **3am runbook:** "the event never arrived" → which rung? (outbox row
  pending? relay lag? queue depth? listener failed?) — the ladder's rungs are
  the diagnostic's stages; "event arrived, nothing happened" → consumer-side
  dedupe hit? handler failed? projection lag?

## How it scales

- **The event graph scales linearly in consumers** until flows need
  coordination — that's the orchestration boundary, not more events.
- **Cross-process scale** (message brokers, streaming) changes the transport,
  not the contracts: versioned facts, idempotent consumers, dedupe on the
  consumer side — the same invariants at higher throughput
  ([redis](../data/redis.md) for the in-house broker path).
- **The honest ceiling:** every event consumer is a module boundary you chose
  not to call across; when a "consumer" needs replies, promote it to a
  collaborator with an API — don't bend events into request/response.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Queued listeners + `ShouldQueue` | ✅ | ✅ | ✅ | ✅ |
| `$afterCommit` on listeners/events | ✅ | ✅ | ✅ | ✅ |
| Event discovery vs explicit registration | ✅ both | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The event surface is stable across the matrix — EDA's real version
sensitivity lives in the surrounding machinery (outbox, queues, Context),
each covered in its own chapter.

## Related

- [The transactional outbox](../messaging/outbox.md) — the durability floor
  for any event that must not be lost.
- [Queues](../messaging/queues.md) — the delivery semantics of async
  listeners (retries, duplicates, poison messages).
- [CQRS](cqrs.md) — projections are the most common consumers; the consistency
  window they create.
- [The modular monolith](modular-monolith.md) — the module boundary that makes
  events the *correct* integration choice.
- [Idempotency](../messaging/idempotency.md) — the consumer-side contract.
- [Observability](../quality/observability.md) — context propagation across
  the hops.
- Roadmap: sagas and process managers, message-broker topologies.
