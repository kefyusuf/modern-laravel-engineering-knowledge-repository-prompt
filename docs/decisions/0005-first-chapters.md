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
