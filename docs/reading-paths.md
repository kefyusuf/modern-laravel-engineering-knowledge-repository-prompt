---
title: Reading paths
---

# Reading paths

The linear reading order in the [README](../README.md) is the full course.
These paths are shortcuts: pick the situation that matches yours, and the
path tells you which chapters to read, in which order, and what each one
solves for you. Every path assumes the
[vocabulary](glossary.md) builds as you go — the first chapter on a topic
always links the prerequisites.

## "I'm shipping a new Laravel app to production for the first time"

Start-to-finish safety net for a greenfield deploy:

1. [Transactions](../chapters/data/transactions.md) — draw the boundaries
   before anything ships.
2. [Queues](../chapters/messaging/queues.md) — the deferred work will be
   half your codebase; get the retry/timeout budgets right.
3. [Deployment](../chapters/runtime/deployment.md) — the release flow,
   expand/contract migrations, health gates.
4. [Observability](../chapters/quality/observability.md) — the four
   signals + request context, wired before the first incident.
5. [Idempotency](../chapters/messaging/idempotency.md) — every retryable
   boundary, closed before it double-charges.

## "I'm building a multi-tenant SaaS"

Isolation is the product. The tenant-scope line appears in four layers —
read them as one discipline:

1. [Row-level security](../chapters/data/row-level-security.md) — the
   structural filter on every query.
2. [Authorization](../chapters/interfaces/authorization.md) — policies
   with tenant scope in the rule (the cross-tenant IDOR test).
3. [Caching](../chapters/data/caching.md) — the key-naming half: scoped
   keys or the cross-tenant leak moves to the cache.
4. [The modular monolith](../chapters/architecture/modular-monolith.md) —
   module-owned tables and the no-cross-tenant-joins rule.
5. [Redis](../chapters/data/redis.md) — the same line in key naming and
   store isolation.

## "I'm investigating a production incident"

The incident toolkit — in the order you'll need it:

1. [Observability](../chapters/quality/observability.md) — request/job
   context, the four signals, runbook entry zero.
2. [Tracing](../chapters/quality/tracing.md) — when logs alone can't localize the hop.
3. [Transactions](../chapters/data/transactions.md) — long-transaction and
   deadlock forensics.
4. [Queues](../chapters/messaging/queues.md) — depth spikes, failed-jobs triage,
   duplicate execution.
5. [Security](../chapters/interfaces/security.md) — if the incident has a
   security shape (injection, privilege flag, leaked secret).

## "I'm reviewing someone else's architecture"

What to look at, in scrutiny order:

1. [The modular monolith](../chapters/architecture/modular-monolith.md) —
   the module boundaries and their enforcement.
2. [Boundaries](../chapters/architecture/boundaries.md) — the layering
   rules inside each module.
3. [Transactions](../chapters/data/transactions.md) — do the boundaries
   and the transaction scopes coincide?
4. [CQRS](../chapters/architecture/cqrs.md) — is the read/write split
   earning its complexity?
5. [DDD](../chapters/architecture/ddd.md) — does the language hold up?

## "My queue/worker setup is misbehaving"

1. [Queues](../chapters/messaging/queues.md) — retry_after vs timeout, poison
   messages, worker lifecycle.
2. [Idempotency](../chapters/messaging/idempotency.md) — duplicates are at-least-once delivery
   meeting a missing contract.
3. [Sagas](../chapters/messaging/sagas.md) — when the flow needs compensation, not more retries.
4. [Broker topologies](../chapters/messaging/broker-topologies.md) — the wiring plan behind the
   queues.

## "I'm scaling the database layer"

1. [Database performance](../chapters/data/database-performance.md) — plans before
   infrastructure.
2. [Online DDL](../chapters/data/online-ddl.md) — big-table schema changes without the
   outage.
3. [Redis](../chapters/data/redis.md) — the cache layer's own scaling ceiling.
4. [Transactions](../chapters/data/transactions.md) — the hot-row and contention dynamics
   underneath.

## "I'm hardening the app"

1. [Security](../chapters/interfaces/security.md) — the four breach
   classes, each closed by a test.
2. [Authorization](../chapters/interfaces/authorization.md) — the IDOR and
   cross-tenant denials, tested like grants.
3. [Content-Security-Policy](../chapters/interfaces/content-security-policy.md)
   — the browser-side containment line.
4. [Testing the failure paths](../chapters/quality/testing.md) — the races and
   failure injections that prove the hardening.

## "I'm adopting realtime/streaming"

1. [Queues](../chapters/messaging/queues.md) — the durable delivery path the
   updates flow through.
2. [The transactional outbox](../chapters/messaging/outbox.md) — the publication guarantee
   before any hub sees the update.
3. [Streaming and real-time](../chapters/runtime/mercure-streaming.md) — the
   options map (broadcasting, SSE, Mercure) and connection capacity.
4. [Tracing](../chapters/quality/tracing.md) — the cross-hop correlation that
   makes realtime debuggable.

## Adding a path

Paths that cover a real situation are welcome content: open a
[chapter proposal](https://github.com/kefyusuf/modern-laravel-engineering-knowledge-repository-prompt/issues/new?template=chapter-proposal.yml)
with the situation and the ordered chapter list. A path must solve a
*sequence*, not just list favorite chapters — each step should change what
the reader does next.
