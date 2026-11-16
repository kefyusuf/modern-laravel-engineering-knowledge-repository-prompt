# ADR 0005: First chapters — depth over coverage

- **Status:** accepted (2026-09-19)
- **Scope:** information architecture, roadmap

## Context

The topic list is long (25 topics). Writing all of them shallowly would fail the
charter ("a missing chapter is better than a shallow one") and produce unreviewable
bulk.

## Options considered

1. **Skeleton stubs for all topics** — visible coverage, hollow content; the stubs
   would rank in search and mislead. Rejected.
2. **6–8 full chapters chosen by production failure cost + dependency ordering**, the
   rest as an explicit roadmap in the README with issue trackers. Chosen.

## Decision

First wave (2026-09):

| Chapter | Why first |
|---|---|
| `data/transactions` | Foundation; every other chapter leans on its semantics. |
| `data/locking` | Highest rate of subtle production bugs (lock scope, expiry, ownership). |
| `data/caching` | Most-deployed technique with the most-wrong defaults (stampede, dogpile). |
| `messaging/queues` | Entry point for all async patterns. |
| `messaging/idempotency` | Required by queues, outbox, inbox, HTTP APIs; rarely implemented correctly. |
| `messaging/outbox` | Flagship integration pattern; ties transactions ↔ queues ↔ idempotency. |
| `architecture/modular-monolith` | Headline architecture decision; defines boundaries other chapters assume. |
| `architecture/cqrs` | Most-misunderstood topic in the list; deserves the bad→good ladder. |

Deferred topics (DDD, EDA, boundaries, Redis internals, APIs, security, authorization,
database performance, Eloquent, observability, testing, Octane, FrankenPHP, deployment,
CI/CD) are listed in the README roadmap **without stub files**.

## Consequences

- Cross-link targets that don't exist yet (e.g. `eda`) are written as plain text in
  `Related` sections with a "roadmap" marker, not as dead links — CI link checker
  enforces this.
- Chapter order in the README reflects a suggested reading order, not completion order.

## Wave 2 (2026-11)

Same policy, next three by failure cost and dependency fit:

| Chapter | Why next |
|---|---|
| `data/eloquent` | The daily-driver ORM's production costs (N+1, hydration, chunking) — referenced by transactions, queues, caching, CQRS. |
| `quality/observability` | Every chapter's *How to operate it* section depends on its primitives; makes the ops half of the handbook real. |
| `runtime/octane` | First runtime chapter; the state-discipline failure class links to caching/observability incidents. |

`octane` ships 📖 (no executable claims in the harness — the CI matrix has no
Swoole/FrankenPHP runtime); `eloquent` and `observability` ship ⚙️ with 5 new
mapped examples.

## Wave 3 (2026-11)

| Chapter | Why next |
|---|---|
| `quality/testing` | The most-referenced roadmap topic — every chapter's *How to test it* leans on its toolkit; also encodes the RED side of the interleave technique. |
| `data/redis` | Referenced by caching/locking/queues; its tests are the first to use `redisRequired()` — executed against CI's Redis service, skipped on laptops. |
| `runtime/deployment` | Completes the release story the queue/octane chapters start; 📖 (procedure claims, no harness-verifiable behavior). |

14 chapters total; mapping grows to 35↔35.

## Wave 4 (2026-11)

| Chapter | Why next |
|---|---|
| `data/database-performance` | Plans-over-guesses with executable claims (EXPLAIN plan assertion, keyset-vs-offset stability, bulk-insert round trips) — the layer under Eloquent at scale. |
| `architecture/boundaries` | The most-referenced missing chapter (modular monolith, CQRS, transactions all lean on it); structural, 📖. |
| `runtime/ci-cd` | Closes the loop the harness started — this repository's own ci.yml is the living example; 📖. |

17 chapters total; mapping grows to 38↔38.

## Wave 5 (2026-11)

| Chapter | Why next |
|---|---|
| `architecture/eda` | Completes the event arc (outbox → CQRS → EDA): the decision framework and reliability ladder, 📖. |
| `interfaces/api-design` | The transport contract clients depend on — rate-limit window semantics executed ⚙️. |
| `interfaces/authorization` | Policies with tenant scope as executable security claims (⚙️ — users table + policy fixtures added to the harness). |

20 chapters total; mapping grows to 41↔41.

## Wave 6 (2026-11) — the original topic list completed

| Chapter | Why next |
|---|---|
| `interfaces/security` | The disciplines Laravel doesn't force: injection (executed: the payload runs in the vulnerable construction, dies in the parameterized one), mass assignment (executed: silent discard vs loud guard), XSS/CSRF/secrets posture. |
| `architecture/ddd` | The pragmatic DDD translation — the aggregate-invariant claim executed framework-free; closes the architecture arc. |
| `runtime/frankenphp` | The serving-stack completion — classic vs worker vs embed, inheriting Octane's state discipline; verified against FrankenPHP docs (Laravel's official path is Octane's integration). |

**23 chapters total — the handbook's original 25-topic list is fully covered;
mapping closes at 44↔44.** Further chapters graduate from the roadmap as
concrete needs appear.

## Wave 7 (2026-11) — deeper-dive wave 1

| Chapter | Why next |
|---|---|
| `messaging/sagas` ⚙️ | The multi-step-flow pattern the outbox/EDA chapters kept referencing; compensation flow + idempotent-undo claims executed. |
| `messaging/broker-topologies` 📖 | The wiring plan behind queues (partitioning, retry lanes, parking lots) — the deepest FAQ the queues chapter generates. |
| `data/online-ddl` 📖 | The big-table schema discipline behind deployment's expand/contract; engine-behavior deep dive. |

**26 chapters total; mapping grows to 46↔46.**

## Wave 8 (2026-11) — deeper-dive wave 2

| Chapter | Why next |
|---|---|
| `data/row-level-security` ⚙️ | Tenant isolation as a structural claim (global scopes executed; PostgreSQL RLS as the second line) — pairs with authorization. |
| `interfaces/content-security-policy` ⚙️ | The browser-side containment line as a tested header contract — pairs with security. |
| `runtime/mercure-streaming` 📖 | The push-delivery options map (broadcasting/Reverb, SSE, Mercure) with shared hygiene. |
| `runtime/preview-environments` 📖 | Lifecycle, data strategy, isolation, cost — CI/CD extended one step outward. |

**30 chapters total; mapping grows to 48↔48. The roadmap's deeper-dive list
is fully covered.**

## Wave 9 (2026-11) — the deepening pass (roadmap folded)

The roadmap's remaining micro-dive items were folded rather than stubbed:
one new chapter + eight section additions across existing chapters.

| Item | Where it landed |
|---|---|
| Exchange patterns + Kafka patterns | **New chapter:** `messaging/external-brokers` 📖 (community drivers named with their support reality; disciplines-carry-over framing) |
| Workflow-engine comparison | [sagas](../../chapters/messaging/sagas.md) § When to reach for an engine |
| Trusted types | [content-security-policy](../../chapters/interfaces/content-security-policy.md) § Trusted Types |
| Per-route policy registries | [authorization](../../chapters/interfaces/authorization.md) § Per-route policy registries |
| Managed-platform DDL | [online DDL](../../chapters/data/online-ddl.md) § Managed platforms |
| Supervisor config templates | [queues](../../chapters/messaging/queues.md) § Supervisor config template |
| Presence systems | [streaming](../../chapters/runtime/mercure-streaming.md) § Presence systems |
| Cost attribution dashboards | [observability](../../chapters/quality/observability.md) § Cost attribution |

**31 chapters total.** No harness changes (all folded content is 📖-level);
mapping stays 48↔48.

## Wave 11 (2026-11) — the engine arc completed

| Item | Why now |
|---|---|
| `messaging/temporal` 📖 | The durable-execution deep dive the sagas chapter's engine section promised — deterministic replay, activities, versioning discipline; verified against Temporal PHP SDK docs and the keepsuit/laravel-temporal integration. |

**32 chapters total.** The saga/engine arc is complete end to end:
[idempotency](../../chapters/messaging/idempotency.md) →
[sagas](../../chapters/messaging/sagas.md) → [Temporal](../../chapters/messaging/temporal.md).

## Wave 10 (2026-11) — the last two deferred chapters + glossary

| Item | Why now |
|---|---|
| `messaging/cdc-streaming` 📖 | The outbox ladder's rung 5 — deferred twice ("different ops discipline"); now the completion of the reliability arc, verified against Debezium docs (Outbox Event Router SMT, snapshot modes). |
| `quality/tracing` 📖 | Observability's explicit "next level" — OpenTelemetry in Laravel (official instrumentation + community packages verified), span discipline, sampling. |
| `docs/glossary.md` | A 31-chapter reference needs a term index; every term links its owning chapter (CI link-checked). |

**33 chapters + glossary.** The handbook now covers every planned topic and
every planned deep dive.
