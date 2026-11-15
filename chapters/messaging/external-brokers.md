---
title: External brokers
topic: external-brokers
status: draft
reviewed-against: "Laravel 12.x docs, RabbitMQ/Kafka package docs, 2026-11"
---

# External brokers

Laravel's queue drivers cover a lot; the moment your integration needs move
**beyond jobs** — routing by content, multi-consumer fan-out, replayable
streams, cross-service consumption — you meet the external brokers: RabbitMQ
and Kafka. This chapter is the "when and how" for both: what each buys, how
they integrate with Laravel today (via community packages — neither has a
first-class driver), and which of this handbook's disciplines carry over
unchanged.

## TL;DR

- **Use RabbitMQ when:** you need **routing** (content-based delivery,
  multiple consumers per message, delayed/redelivery semantics) beyond the
  queue-driver's one-message-one-consumer model — and your volume is
  moderate.
- **Use Kafka when:** you need **event streams** — multiple independent
  consumers replaying the same history, ordered per partition, retained —
  i.e., the CDC/EDA scale path ([outbox](outbox.md) rung 5) with volume to
  justify its operations.
- **Don't use either** because the architecture diagram has a broker icon —
  the database queue and Redis
  ([broker topologies](broker-topologies.md), [redis](../data/redis.md))
  cover most Laravel systems' real needs.
- **The classic production incident:** the broker adopted for "decoupling",
  with the disciplines abandoned at the boundary — no idempotency on
  consumers (Kafka's at-least-once rebalances duplicate), no outbox (the
  publish is lost with the process), no topology doc. The broker inherits
  every discipline you had; it adds none.

## Problem

Laravel's queue model is **work distribution**: one message, one consumer,
one outcome — perfect for jobs. External brokers solve different problems
that jobs model badly:

- **Fan-out to independent consumers:** one fact, three systems reacting —
  as a queue, three copies and three failure domains pretending to be one.
- **Content routing:** "messages about invoices for tenant 7 to the billing
  service" — routing keys and exchanges, not queue names.
- **History:** a consumer joining later (or after a crash) replaying past
  events — queues delete on consumption; logs retain.

Each broker implements these differently, and each shifts operational
ownership (clusters, rebalancing, partition planning) onto your team.

## When to use it

- **RabbitMQ:** routing-heavy integrations (fan-out per event type, delayed
  retries as topology), moderate throughput, you want mature tooling and
  simpler ops than Kafka.
- **Kafka:** event streams at volume — the
  [outbox](outbox.md)-to-CDC path, analytics pipelines, cross-service event
  history with replay — where ordering per entity and retention are
  requirements.
- **When a queue driver already covers it:** stay there —
  [broker topologies](broker-topologies.md) on the database/Redis/SQS
  drivers is the simpler system that works.

## When NOT to use it

- **As a job runner.** RabbitMQ/Kafka via a queue driver for plain jobs is
  Redis's job with more ops — the drivers exist, the need usually doesn't.
- **Before the disciplines exist.** If consumers aren't idempotent, facts
  aren't versioned, and publishes aren't durably committed
  ([outbox](outbox.md)), the broker adds a moving part to an unreliable
  system.
- **Volume that doesn't justify a cluster.** Both brokers are distributed
  systems with real operational surface — thousands of messages per day is
  a queue, not a broker.

## RabbitMQ: exchange patterns

RabbitMQ's model: **publishers send to exchanges; exchanges route to queues
by rules; consumers read queues.** The exchange types ARE the patterns:

| Exchange | Routes by | Use for |
|---|---|---|
| **Direct** | Exact routing key | Point-to-point: `invoice.paid` → billing queue |
| **Topic** | Key patterns (`invoice.#`, `*.rejected`) | Selective subscription: consumers pick the fact families they handle |
| **Fanout** | Everything | Broadcast: one fact, N independent queues |
| **Headers** | Message headers | Rarely needed; topic covers most |

The Laravel integration today is the community queue driver
(**`vladimir-yuldashev/laravel-queue-rabbitmq`** — the established driver,
major versions tracking Laravel's; a known compatibility quirk exists with
the newest Laravel 12 point releases, so pin deliberately — and the
newer **`iamfarhad/laravel-rabbitmq`** as an alternative). Two things to
know before adopting: it integrates RabbitMQ as a *queue driver* (work
distribution — exchange/routing design sits in your publishing code), and
it supports Horizon. The exchange/routing layer beyond job distribution is
your code talking to the RabbitMQ client directly — the patterns above are
yours to implement, and [broker topologies](broker-topologies.md)' rules
(name by concern, retry lanes, parking lots) map onto dead-letter exchanges
and routing keys.

The disciplines that carry over unchanged: idempotent consumers
([idempotency](idempotency.md)), durable publication
([outbox](outbox.md)), per-queue monitoring with alerts
([observability](../quality/observability.md)), and the retry/parking-lot
topology.

## Kafka: stream patterns

Kafka's model: **topics are partitioned logs; consumers read in groups,
each partition ordered, history retained.** The patterns:

- **The outbox-to-Kafka relay** — the durable publication path
  ([outbox](outbox.md)): the relay publishes committed facts to a topic;
  the log IS the delivery guarantee, with replay for new/late consumers.
- **Consumer groups** — one group per service: independent consumption of
  the same topic, each group's offset managed separately. Adding a consumer
  service = adding a group, not re-plumbing producers.
- **Partitioning by aggregate** — ordering is per-partition: partition by
  `order_id` (key) so one order's events stay ordered
  ([outbox](outbox.md) ordering discipline, now native).
- **Replay for recovery** — a broken consumer resets its offset and
  reprocesses history; idempotency
  ([idempotency](idempotency.md)) stops being optional.

The Laravel integration is the community package
(**`mateusjunges/laravel-kafka`** — actively maintained, Laravel 11/12, a
`Kafka` facade for producing and consuming). The operational ownership is
the real cost: partitions, replication, rebalancing, retention — Kafka is a
cluster you operate (or rent), and its semantics
(at-least-once + ordering per partition) must match your consumers'
[idempotency](idempotency.md) design.

## The simplest correct implementation

The decision, expressed as a boundary: **jobs stay on the queue drivers.**
Work distribution keeps its retry budgets, failed-jobs discipline, and
[broker topologies](broker-topologies.md) — external brokers join for
routing and streaming needs, not for running jobs. Concretely: a job class
dispatched to `queues:payments` is unchanged by RabbitMQ/Kafka adoption; what
changes is that *facts* (not jobs) start flowing through the broker — via the
[outbox](outbox.md) relay targeting a Kafka topic, or via direct exchange
publishes for routing-heavy integrations.

And the event-stream path, when the outbox relay targets Kafka instead of a
queue — the relay's code shape is identical to
[the outbox chapter's](outbox.md); only the sink changes (the Kafka producer
instead of a queue job), and the consumers become consumer-group members
reading the topic. The correctness contract doesn't move: versioned payloads,
idempotent consumers, dedupe on the consumer side.

## What production requires

- **The disciplines port — write them down per boundary:** idempotent
  consumers, versioned payload contracts, durable publication (outbox),
  topology documentation, per-topic/queue observability. A broker is not a
  substitute for any of them.
- **Cluster operations as a workstream:** version upgrades, partition
  planning, rebalance handling, retention policy — scheduled work with an
  owner, not drift.
- **Broker-side failure drills:** broker down (producers buffer via outbox —
  verify), consumer group rebalance (duplicates — verify dedupe), partition
  reassignment (ordering per key — verify).
- **Schema governance:** payload contracts versioned and checked
  ([outbox](outbox.md) contract tests) — at broker scale, a breaking payload
  is a fleet-wide incident.

## What usually goes wrong

1. **The non-idempotent consumer under rebalance.** *Root cause:* Kafka's
   at-least-once + rebalances re-deliver; consumers assumed exactly-once.
   *Detection:* duplicates in consumer state after every rebalance.
   *Fix:* consumer-side dedupe ([idempotency](idempotency.md)); design
   consumers as idempotent projections.
2. **The lost publish.** *Root cause:* producing straight from the request
   path — broker hiccup = lost fact. *Detection:* gaps consumers can prove.
   *Fix:* the outbox; the broker is the delivery layer, the transaction is
   the truth.
3. **Partition hotspots.** *Root cause:* partition key with low cardinality
   (tenant with giant volume, or null keys). *Detection:* one partition
   consuming most of the throughput. *Fix:* key design review
   ([database performance](../data/database-performance.md) hot-spot logic
   applies).
4. **Queue-driver adoption without topology design.** *Root cause:* the
   RabbitMQ driver used as "a fancier Redis" — everything on one default
   exchange. *Detection:* routing done in consumer code with if-statements.
   *Fix:* the exchange patterns above; [broker topologies](broker-topologies.md)
   mapped to exchanges/DLX.
5. **Package-version drift.** *Root cause:* community drivers tracking
   Laravel majors — the newest Laravel point release breaking a driver pin.
   *Detection:* the CI matrix catching it (this handbook's own harness
   pattern). *Fix:* deliberate pins + the upgrade-preview habit
   ([ci-cd](../runtime/ci-cd.md)); know the alternative packages.
6. **Two brokers, one responsibility.** *Root cause:* RabbitMQ AND Kafka
   both adopted, boundaries blurred — which system owns which fact?
   *Detection:* events published to both "for safety". *Fix:* one system of
   record per fact class; the [reliability ladder](../architecture/eda.md) decides per need,
   documented.

## How to test it

- **Consumer idempotency:** the harness's
  [idempotency](idempotency.md) patterns — replay the same message, assert
  one effect. Broker-agnostic and testable everywhere.
- **Contract tests on payloads:** producer-side schema assertions
  ([outbox](outbox.md)) — at broker scale these are fleet protection.
- **Broker behaviors verified on the real broker in staging:** rebalance
  duplicates, ordering per key, retention/replay — staging drills, not unit
  tests (the harness scope stays SQLite/Redis + the Laravel-side claims).
- **Upgrade previews:** community drivers track Laravel majors — the CI
  matrix ([ci-cd](../runtime/ci-cd.md)) is what catches driver-vs-framework drift
  before production.

## How to operate it

- **Broker dashboards:** queue depths / consumer lag / partition skew /
  rebalance counts — the [broker topologies](broker-topologies.md) metrics
  per transport.
- **The disciplines audit:** a periodic check that every consumer is
  idempotent, every payload is versioned, every publish is durable — the
  broker inherits discipline or inherits incidents.
- **3am runbook:** consumer stuck → lag + rebalance history; duplicates
  reported → idempotency layer missing or broken; publish gaps → the outbox
  relay (broker is downstream of the truth, not the truth).

## How it scales

- **RabbitMQ scales to serious throughput** with the topology carrying the
  design (federated clusters, shovel links) — operational complexity is the
  price curve.
- **Kafka scales by partition count and consumer groups** — the horizontal
  story is strong; the planning (partition counts, retention) is the work.
- **The honest framing:** both brokers are infrastructure with teams behind
  them — adopt with an owner and a drill, or stay on the queue drivers.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| First-class RabbitMQ/Kafka driver in core | ❌ community packages | ❌ | ❌ | ❌ |
| `vladimir-yuldashev/laravel-queue-rabbitmq` | ✅ (v8+ covers 10) | ✅ (v14.x) | ✅ (v14.x; pin deliberately — a quirk exists with the newest L12 point releases) | ✅ via alternatives |
| `mateusjunges/laravel-kafka` | ⚠️ (check the package's Laravel constraint) | ✅ (v2.x) | ✅ (v2.13+) | ✅ (verify constraint per release) |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The community packages track Laravel majors independently — the matrix
rule ([ci-cd](../runtime/ci-cd.md)) is what verifies their compatibility per release;
treat their pins as part of your dependency contract.

## Related

- [Broker topologies](broker-topologies.md) — the wiring plan; exchanges and
  DLX map onto it.
- [The transactional outbox](outbox.md) — durable publication; the Kafka
  relay's source.
- [EDA](../architecture/eda.md) — the rung-5 scale path this chapter
  implements.
- [Idempotency](idempotency.md) — the consumer contract both brokers make
  mandatory.
- [Redis](../data/redis.md) / [Queues](queues.md) — the simpler systems that
  cover most needs first.
- Roadmap:Debezium-class CDC deep dive, schema-registry patterns.
