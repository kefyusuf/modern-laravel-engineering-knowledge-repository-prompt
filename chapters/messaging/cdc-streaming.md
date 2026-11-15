---
title: CDC and Debezium
topic: cdc-streaming
status: draft
reviewed-against: "Debezium 3.x docs, 2026-11"
---

# CDC and Debezium

Change Data Capture (CDC) is the [outbox](outbox.md) reliability ladder's
final rung — **rung 5, the scale path**: instead of a relay polling the
outbox table and publishing, a log-based CDC system reads the database's
transaction log and streams every committed change. Debezium is the standard
open-source implementation. This chapter is the hand-off between the two
worlds: what Laravel's side looks like (spoiler: the outbox contract doesn't
change), what Debezium adds, and the operational reality that makes rung 5 a
deliberate step rather than an upgrade.

## TL;DR

- **Use it when:** the outbox relay's polling is the measured bottleneck —
  high write volume where per-write latency, relay throughput, or the mark-
  update contention ([outbox](outbox.md) "the new hot spot" failure) outgrow
  polling — and the team can operate Kafka Connect.
- **Don't use it when:** the relay meets your freshness bound — rung 3/4
  works, is simpler, and its failure modes are already operated
  ([outbox](outbox.md), [sagas](sagas.md)).
- **The classic production incident:** CDC adopted as a "modernization", and
  the disciplines abandoned at the boundary — consumers stopped deduping
  ("Kafka handles it"), payload contracts stopped being versioned ("the
  schema registry handles it"). CDC inherits your discipline; it adds none.

## Problem

The polling relay's costs scale with write volume: one outbox INSERT per
write, the relay's claim transaction, the mark-update
([outbox](outbox.md)). At high throughput these become a second write
hotspot next to the business tables. CDC removes the polling entirely: the
database's transaction log is read directly (MySQL binlog, PostgreSQL WAL)
by a connector running in Kafka Connect, and every committed change —
including your outbox INSERTs — flows into Kafka without the application
publishing anything.

The correctness model is preserved exactly: the outbox INSERT still commits
with the state change ([Transactions](../data/transactions.md)), still
carries the versioned payload contract
([outbox](../messaging/outbox.md)), and consumers still dedupe
([idempotency](idempotency.md)). What changes is only the delivery
mechanism — from "poll a table" to "tail a log".

## When to use it

- The relay is the measured bottleneck: relay lag grows with volume, or the
  outbox table's write contention shows in the database's hot spots.
- Multiple systems consume the same change stream (a real event stream, not
  just one relay target — [external brokers](external-brokers.md) Kafka
  section).
- You already operate Kafka and Kafka Connect — the marginal ops cost is
  the connector, not a new platform.

## When NOT to use it

- **The relay is fine.** Rung 3/4 delivers with a bounded, monitored lag —
  the ladder buys nothing you're missing ([outbox](outbox.md) spectrum).
- **No Kafka.** Debezium emits into Kafka Connect; adopting CDC without
  Kafka means adopting Kafka.
- **As a database-sync "backup".** CDC is an event-delivery mechanism, not
  a replication or recovery strategy — different discipline, different
  tools.
- **Before the consumers are ready.** CDC's at-least-once + per-partition
  ordering semantics are the same ones [external brokers](external-brokers.md)
  documents — consumers dedupe and handle out-of-order per aggregate, or
  the stream is a liability.

## How it works (the Debezium shape)

```text
Laravel app:   BEGIN; write state; INSERT outbox_messages (...); COMMIT;
                          │ (the transaction log)
                          ▼
Debezium connector (in Kafka Connect): tails the log (MySQL binlog /
               PostgreSQL WAL), emits a change event per row
                          │
                          ▼ (Outbox Event Router SMT)
Kafka topics:  order.shipped, invoice.paid ... (per-aggregate routing)
                          │
                          ▼
Consumers:     any service — Laravel (via consumer packages) or otherwise
```

Three Debezium pieces matter for the outbox design:

1. **The connector** tails the transaction log per database — MySQL needs
   binlog in ROW format with appropriate binlog privileges; PostgreSQL
   needs a logical replication slot and WAL settings. Both are database
   configuration with real capacity implications.
2. **The Outbox Event Router** (a Single Message Transform) — Debezium's
   built-in processor for exactly this pattern: it watches the outbox table
   and emits each row **as the event it describes** — routed by
   `aggregate_type` to a per-aggregate topic, keyed by `aggregate_id`
   (per-aggregate ordering, native), with the payload columns as the
   message body. Your outbox table's column layout becomes the router's
   configuration.
3. **Snapshot modes** — on first start the connector must capture existing
   rows: `initial` (default; a blocking consistent snapshot), `when_needed`
   (re-snapshots when offsets are lost), and for large outbox tables the
   incremental snapshot signal (`snapshot.incremental`) that chunks the
   work ([database performance](../data/database-performance.md) chunking
   discipline, engine-native).

The Laravel side: **the [outbox chapter's](outbox.md) reference
implementation stays as-is** — the same table, the same
`Outbox::publish()`-inside-the-transaction rule, the same payload contract.
The relay command is *replaced* by the connector; consumers, dedupe, and
payload versioning are untouched.

## The simplest correct implementation

The migration of rung 3 → rung 5, in order:

```text
1. Stand up Kafka + Kafka Connect; install the Debezium MySQL/Postgres connector.
2. Verify the database prerequisites (binlog/ROW format + privileges on
   MySQL; logical slot + WAL keep-parameters on PostgreSQL).
3. Configure the connector to capture ONLY the outbox table (others later).
4. Apply the Outbox Event Router SMT: aggregate-type routing, aggregate_id
   as the key, the payload column as the body.
5. Start in snapshot mode=initial (existing outbox rows stream first), then
   it tails the log continuously.
6. Run BOTH delivery paths briefly (relay + CDC) and compare consumer
   receipts — the cutover proof.
7. Retire the relay; the outbox table and Outbox::publish() stay.
```

Step 6 is the whole migration's trust argument: two delivery paths,
one consumer ledger ([idempotency](idempotency.md)), receipts compared until
they match — then the relay retires.

## What production requires

- **Database prerequisites as configuration:** MySQL binlog format/row image
  and a dedicated replication user; PostgreSQL logical slot (which pins WAL
  cleanup — an offline connector can hold back WAL and grow the disk:
  monitor and drop/recreate deliberately).
- **The schema-evolution story:** outbox table columns and payload shapes
  evolve — the connector's schema handling (schema registry or JSON) and
  consumers' version tolerance are the [outbox](outbox.md) contract
  discipline, now enforced at the stream.
- **Connector monitoring:** connector state, lag behind the log head,
  snapshot progress — the relay-lag metric's successor
  ([outbox](outbox.md) operate section).
- **The consumers' at-least-once posture:** log replay, connector restarts,
  and offset resets all redeliver — the inbox dedupe
  ([idempotency](idempotency.md)) is the only thing that keeps
  effectively-once true ([eda](../architecture/eda.md) rung 4 semantics,
  now with a log instead of a table).
- **The failure drill:** connector crash → restart + resume from offset;
  Kafka unavailability → the log holds, nothing is lost (the log IS the
  durability); database failover → connector re-points — each drilled
  before cutover ([deployment](../runtime/deployment.md) discipline).

## What usually goes wrong

1. **WAL/binlog retention lost to a paused connector.** *Root cause:* a
   stopped PostgreSQL logical slot holds back WAL cleanup — disk fills.
   *Detection:* disk usage growth while the connector is down. *Fix:*
   monitor slot lag; drop/recreate the slot deliberately (accepting a
   re-snapshot).
2. **The disciplines abandoned at the boundary.** *Root cause:* "CDC means
   exactly-once" — false: log replay, connector restarts, and offset resets
   redeliver. *Detection:* duplicate effects in consumers. *Fix:* the
   inbox dedupe stays ([idempotency](idempotency.md)).
3. **Snapshot storms.** *Root cause:* `initial` snapshot on a large outbox
   table with default chunking. *Detection:* database load spike at
   connector start. *Fix:* incremental snapshots or time-boxed windows.
4. **Schema evolution breaks the router.** *Root cause:* outbox columns
   renamed (the router's config references them). *Detection:* connector
   errors at the first changed commit. *Fix:* the router config is
   versioned with the migration; payload contracts unchanged
   ([outbox](outbox.md)).
5. **Consuming every table "because it's free".** *Root cause:* the
   connector captures the whole database; consumers drown in unrelated
   change events. *Detection:* topic sprawl; consumer lag from noise.
   *Fix:* capture the outbox (and later, deliberately, other tables per
   need).
6. **The relay and CDC running "for safety" forever.** *Root cause:* no
   cutover decision. *Detection:* consumers deduping double-deliveries
   forever. *Fix:* the receipts-comparison cutover (step 6), then retire
   one path.

## How to test it

- **The outbox claims are already executed** — the
  [outbox harness](../../examples/tests/OutboxExamplesTest.php) covers
  commit-coupling, relay semantics, and inbox dedupe; CDC changes the
  delivery mechanism, and those contracts hold by construction (the outbox
  row is the same row Debezium captures).
- **Staging drills are the CDC tests:** connector against a
  production-shaped database — snapshot duration, lag under write load,
  failover recovery, and the router's topic/key/payload mapping verified
  against the contract.
- **The cutover comparison** is the acceptance test: relay and CDC both
  live, receipts matched per event id over a meaningful window
  ([idempotency](idempotency.md) ledger as the receipt source).
- The engine behaviors (binlog/slot semantics) are database-verified, per
  engine — the harness does not run Kafka Connect.

## How to operate it

- **The connector's four signals:** running state, log-lag (how far behind
  the head), snapshot progress, error rate — plus Kafka-side consumer lag
  ([external brokers](external-brokers.md)).
- **Slot/WAL discipline (PostgreSQL):** slot lag is a disk-risk metric with
  its own alert; connector restarts are routine, slot drops are decisions.
- **Schema-evolution cadence:** outbox shape changes ship with the router
  config and consumer tolerance verified — the [outbox](outbox.md) contract
  test suite, extended to the stream.
- **3am runbook:** consumers starving → connector state + log-lag first
  (the stream is upstream of everything); duplicates → consumer dedupe
  (the usual suspect remains the consumer); disk → slot lag (PostgreSQL)
  or binlog retention (MySQL).

## How it scales

- **The log scales with the database, not with consumers** — adding
  consumer services is adding consumer groups
  ([external brokers](external-brokers.md)), not adding relay load.
- **Per-aggregate topics** partition naturally; hot aggregates hit the same
  per-key ordering limits as any stream — the ordering requirements
  ([outbox](outbox.md)) were already scoped per aggregate.
- **The ceiling:** CDC makes event delivery stop being the bottleneck — at
  that point the scaling conversation moves to consumers and the domain
  ([CQRS](../architecture/cqrs.md)), where it belonged anyway.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| The outbox contract (table + publish + payload) | ✅ | ✅ | ✅ | ✅ |
| Framework involvement in CDC | ❌ none — Debezium reads the log | ❌ | ❌ | ❌ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

CDC is deliberately framework-agnostic — the outbox table is the interface,
and Debezium reads the log, not the application. Debezium's own versioning
(3.x at the time of writing) and the database's log capabilities are the
version-sensitive inputs, tracked on the upstream docs.

## Related

- [The transactional outbox](outbox.md) — the contract CDC streams; rungs
  3-4 are its polling implementation.
- [EDA](../architecture/eda.md) — the reliability ladder this completes.
- [External brokers](external-brokers.md) — Kafka semantics, consumer
  groups, idempotency at stream scale.
- [Idempotency](idempotency.md) — the consumer-side contract CDC makes
  permanent.
- [Transactions](../data/transactions.md) — the commit boundary the log
  captures.
- Roadmap: Debezium operations deep dive, schema-registry governance.
