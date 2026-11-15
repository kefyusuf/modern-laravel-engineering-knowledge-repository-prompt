---
title: Message broker topologies
topic: broker-topologies
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
---

# Message broker topologies

A broker topology is the *wiring plan* behind [queues](queues.md): which
queues exist, who publishes to what, where retries go, where poison messages
die, and what the consumer's failure does to the flow. Laravel gives you the
mechanics (drivers, connections, workers); the topology — the part that
determines whether a failure is contained or contagious — is your design. This
chapter is that design, transport-aware but Laravel-first.

## TL;DR

- **Use it (a deliberate topology) when:** more than one queue exists, or any
  consumer's failure can starve another's — i.e., as soon as async work is
  real.
- **Don't do it:** one queue for everything "because we can scale later" —
  the coupling you create (head-of-line blocking, blast radius, per-concern
  tuning) is the thing you cannot refactor away once two consumers exist.
- **The classic production incident:** the bulk export job and the payment
  webhook sharing one queue — a slow export starves payments for an hour, and
  nobody can throttle one without stopping the other.

## Problem

A single queue is a shared failure and latency domain:

- **Head-of-line blocking:** a slow job at the front delays everything behind
  it, regardless of importance.
- **Blast radius:** the poison message stops *all* consumers on the queue
  (or burns retries on unrelated work).
- **Tuning conflicts:** the webhook needs fast retries; the export needs long
  timeouts; one queue can't have both.
- **Failure invisibility:** "the queue is backed up" — which jobs? Whose?

Topology fixes each by partitioning: queues by **concern and criticality**,
with an explicit path for failures (retries → parking lot).

## When to use it

- The moment two consumers have different latency/criticality profiles
  (payments vs bulk exports — [queues](queues.md)' first scaling lever).
- Any flow where a poison message must not block healthy traffic.
- Multi-tenant systems where one noisy tenant must not starve the rest.

## When NOT to use it

- **Queue-per-everything.** Each queue is a worker pool, config, and
  monitoring obligation — partition by *bounded concern*, not by class name.
- **Micro-topologies for tiny volume.** Ten jobs a day need one queue and a
  good retry policy, not a routing graph.
- **Broker features as architecture.** A dead-letter exchange doesn't make
  failure handling exist — [queues](queues.md)' failed-jobs discipline and
  [sagas](sagas.md) compensation still own that.

## The reference topology (Laravel-native)

```text
                            ┌─→ workers(payments) ── retry queue ─┐
producers ──→ queues:payments ┤                                    ├→ parking_lot (alert)
                            └─→ failed_jobs (alert)               ┘
producers ──→ queues:exports ──→ workers(exports) ── retry queue ── parking_lot
producers ──→ queues:webhooks ──→ workers(webhooks) ...
```

The rules the diagram encodes:

1. **One queue per concern-criticality pair** — `payments`, `webhooks`,
   `exports` — never per class. Workers are dedicated per queue
   ([Queues](queues.md) supervision posture).
2. **Retries are a topology decision, not just a number:** the failure path is
   explicit — short retry backoff on the same queue, then a slow retry queue,
   then the parking lot ([idempotency](idempotency.md) makes every hop safe).
3. **The parking lot is monitored, not a graveyard** — it's the failed-jobs
   discipline with a name and an alert ([observability](../quality/observability.md)).
4. **Publishers never pick queues ad hoc** — the queue name lives beside the
   job (its `viaQueue()`/dispatch site), reviewed like a route.

## The simplest correct implementation

The two-queue split that pays for itself immediately:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverPartnerWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The queue IS the topology: latency-critical, isolated, its own
    // worker pool and retry budget (Queues chapter retry policy).
    public function __construct(public readonly int $endpointId) {}

    public function viaQueue(): string
    {
        return 'webhooks';
    }

    public function handle(): void
    {
        // ...
    }
}
```

```php
<?php

// Workers, per queue — one supervisor program each (Queues chapter):
//   php artisan queue:work --queue=webhooks
//   php artisan queue:work --queue=exports
// The critical queue is never behind the bulk one; congestion is visible
// per queue (queue:monitor) instead of averaged.
```

## What production requires

- **Backpressure per queue:** `queue:monitor` on every named queue with its
  own threshold — payments' "too deep" is 50; exports' is 5,000.
- **Retry topology:** `$tries`/`backoff` per job ([Queues](queues.md)) + an
  explicit slow lane for the "dependency down for 10 minutes" class — without
  it, long outages burn all attempts against a dead dependency.
- **Poison handling:** after `$maxExceptions`, the message lands somewhere a
  human triages — Laravel's failed_jobs is that place; name its review
  cadence.
- **Ordering needs are topology needs:** per-entity serialization
  ([Queues](queues.md)) means per-entity queues or sequence-checked handlers
  — decided in the topology, discovered nowhere.
- **Multi-tenant fairness:** a noisy tenant's exports must not starve others'
  webhooks — partitioning by criticality + tenant-aware dispatch (separate
  queues or rate-limited dispatch per tenant).

## What usually goes wrong

1. **The shared-queue starvation.** *Root cause:* one queue, one slow job
   class. *Detection:* p99 latency of *unrelated* jobs correlated with one
   job's volume. *Fix:* split by concern; dedicated worker pools.
2. **The invisible poison message.** *Root cause:* retries exhausted into a
   failed-jobs table nobody reads. *Detection:* partner complaints weeks
   later. *Fix:* the parking lot alert + review cadence.
3. **Retry storms against a dead dependency.** *Root cause:* flat retries on
   the main queue. *Detection:* queue depth + error rate dominated by one
   job class. *Fix:* the slow retry lane + circuit-breaker posture in the
   dependency client.
4. **Unbounded queue growth accepted as normal.** *Root cause:* "the workers
   will catch up". *Detection:* depth trending up for days. *Fix:* backpressure
   at dispatch (rate-limit the producer), not just more workers.
5. **Topology drift.** *Root cause:* new jobs dispatched to whatever queue the
   author remembered. *Detection:* queue contents that don't match their
   concern. *Fix:* the queue name reviewed with the job; the topology
   diagram in the module README ([modular monolith](../architecture/modular-monolith.md)).

## How to test it

- **Queue placement is testable:** `Queue::fake()` asserts the job was pushed
  to `onQueue('webhooks')` — the topology's routing claim, cheap to pin.
- **Isolation is testable:** a worker dedicated to `webhooks` never executes
  export jobs — an integration assertion on the supervisor config (or a
  local `queue:work --queue=webhooks --once` smoke).
- **Retry-path claims:** the failure-injection patterns
  ([testing](../quality/testing.md)) per queue's policy.
- Cross-engine topology behavior (exchanges, routing keys, DLX) is verified
  on the real broker in staging — the harness's SQLite/Redis scope covers the
  Laravel-side claims only.

## How to operate it

- **Per-queue dashboards:** depth, oldest-job age, failure rate, retry
  pressure — the [observability](../quality/observability.md) section's
  per-concern metrics, one panel per queue.
- **The topology doc lives in the repo:** queues, consumers, retry paths,
  parking lots — one diagram, updated like code.
- **3am runbook:** depth spike on one queue → its consumers and its
  dependency; the other queues' health first (isolation working?); parking
  lot growth → the poison class; worker OOM → memory profile per queue's job
  types.

## How it scales

- **Partitioning scales horizontally:** add worker pools per queue
  independently — the topology is the scaling unit
  ([Queues](queues.md) scaling section).
- **Cross-broker growth:** the same topology maps onto RabbitMQ exchanges /
  routing keys or Redis streams — the queue names and failure paths carry
  over; the mechanics change ([redis](../data/redis.md)).
- **The ceiling:** when topologies multiply beyond a diagram, the answer is
  consolidating *concerns*, not automating sprawl.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Named queues / `viaQueue()` / per-connection workers | ✅ | ✅ | ✅ | ✅ |
| `queue:monitor` per queue | ✅ | ✅ | ✅ | ✅ |
| Job batching / chains (topology-free fan-out) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Topology is a design layer over stable queue mechanics — no version fork
matters here beyond the engine choices in [Queues](queues.md).

## Related

- [Queues](queues.md) — the mechanics this topology wires; retry/timeout
  budgets.
- [The transactional outbox](outbox.md) — the producer side's reliability,
  orthogonal to topology.
- [Sagas](sagas.md) — multi-step flows that ride the topology.
- [Redis](../data/redis.md) — the redis driver's infrastructure posture.
- [Observability](../quality/observability.md) — per-queue telemetry.
- Roadmap: exchange-pattern deep dive (RabbitMQ), Kafka integration patterns.
