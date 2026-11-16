---
title: Glossary
---

# Glossary

The handbook's working vocabulary — one line per term, each linking the
chapter that owns it. Terms are defined as *this handbook uses them*; where
an industry term means something broader, the chapter's definition is the
contract.

## A–C

- **Aggregate** — a consistency boundary: one root object, invariants
  enforced on every mutation, one transaction per aggregate.
  [DDD](../chapters/architecture/ddd.md)
- **At-least-once delivery** — the guarantee that a message arrives at least
  once (duplicates possible); the reason [idempotency](../chapters/messaging/idempotency.md)
  exists. [Queues](../chapters/messaging/queues.md)
- **Bounded context** — a boundary inside which terms mean one thing; the
  module unit. [Modular monolith](../chapters/architecture/modular-monolith.md),
  [DDD](../chapters/architecture/ddd.md)
- **Blast radius** — how far one failure spreads; the property queue
  partitioning exists to shrink. [Broker topologies](../chapters/messaging/broker-topologies.md)

## D–F

- **Dead letter / parking lot** — where exhausted messages land for human
  triage. [Broker topologies](../chapters/messaging/broker-topologies.md)
- **Dedupe store** — a unique-indexed table whose claim insert is the
  idempotency guarantee. [Idempotency](../chapters/messaging/idempotency.md)
- **Deadlock** — two transactions holding locks the other needs; the engine
  kills one (SQLSTATE 40001). Retry the loser, order multi-resource access
  consistently. [Transactions](../chapters/data/transactions.md)
- **CDC (Change Data Capture)** — streaming committed changes from the
  database's transaction log; the outbox ladder's rung 5.
  [CDC and Debezium](../chapters/messaging/cdc-streaming.md)
- **Consumer group** — Kafka's independent-consumption unit: each group
  reads the full topic, offsets managed per group.
  [External brokers](../chapters/messaging/external-brokers.md)
- **Eventual consistency** — consumers seeing updates after a bounded
  window instead of atomically; the window is a product decision with a
  metric. [EDA](../chapters/architecture/eda.md),
  [CQRS](../chapters/architecture/cqrs.md)
- **Expand/contract** — schema changes split across two releases so old code
  survives the overlap. [Deployment](../chapters/runtime/deployment.md)

## G–M

- **Global scope** — an Eloquent filter applied to every query for a model;
  the structural tenant-isolation tool.
  [Row-level security](../chapters/data/row-level-security.md)
- **Head-of-line blocking** — one slow job delaying everything behind it on
  a shared queue. [Broker topologies](../chapters/messaging/broker-topologies.md)
- **IDOR** — insecure direct object reference: accessing another actor's
  resource because a check was scoped by id only.
  [Authorization](../chapters/interfaces/authorization.md)
- **Inbox** — the consumer-side dedupe table that turns at-least-once into
  effectively-once. [Outbox](../chapters/messaging/outbox.md),
  [Idempotency](../chapters/messaging/idempotency.md)
- **Keyset (cursor) pagination** — paging by "after this key" so cost and
  windows stay stable under concurrent writes.
  [Database performance](../chapters/data/database-performance.md)
- **Metadata lock** — the schema-level lock DDL waits on; long transactions
  turn migrations into outages. [Online DDL](../chapters/data/online-ddl.md)

## N–R

- **N+1 queries** — one query per item of a list; the guardrail makes it a
  test failure. [Eloquent at scale](../chapters/data/eloquent.md)
- **Outbox** — a table written in the same transaction as a state change,
  relayed to the broker asynchronously. The handbook's reliability anchor.
  [Outbox](../chapters/messaging/outbox.md),
  [Transactions](../chapters/data/transactions.md)
- **Parking lot** — see dead letter.
- **Poison message** — a message whose handling fails identically every
  time; contained by retry ceilings and the failed-jobs flow.
  [Queues](../chapters/messaging/queues.md)
- **Projection** — a denormalized read model maintained by events.
  [CQRS](../chapters/architecture/cqrs.md)
- **Process manager** — the state-owning orchestrator of a saga's steps.
  [Sagas](../chapters/messaging/sagas.md)

## S–W

- **Saga** — a multi-step flow with compensation; partial failure is undone
  through idempotent reverse actions.
  [Sagas](../chapters/messaging/sagas.md)
- **Sampling (head / tail)** — head sampling decides at trace start (can
  miss errors); tail sampling at the collector keeps all error and slow
  traces. [Tracing](../chapters/quality/tracing.md)
- **Span** — one timed, attributed unit of work in a trace; spans link into
  the cross-hop tree. [Tracing](../chapters/quality/tracing.md)
- **Stampede (cache)** — many requests recomputing one expired key at once;
  closed by stale-while-revalidate or lock-on-miss.
  [Caching](../chapters/data/caching.md)
- **Tenant scope** — the structural filter keeping tenants isolated on every
  read. [Row-level security](../chapters/data/row-level-security.md)
- **Trace** — the connected tree of spans across one flow's hops.
  [Tracing](../chapters/quality/tracing.md)

Found a term used but missing here? That's a documentation finding — open a
correction issue via the repository's issue templates.
