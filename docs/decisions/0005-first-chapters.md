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
