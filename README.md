# Modern Laravel Engineering

**A production engineering handbook for intermediate → senior Laravel engineers.**
Not a syntax tutorial: every chapter answers what problem a technique solves, when to
avoid it, what a production implementation requires, how it is tested and operated,
and exactly which Laravel/PHP versions change the answer.

**Status: reviewed (2026-11).** All 31 chapters passed three rounds of
independent adversarial review (60 findings, all resolved); chapters marked
⚙️ have their load-bearing examples **executed locally via Docker across the
full Laravel 10–13 matrix** (2026-11; see [docs/examples.md](docs/examples.md)
for the recorded run), and the CI workflow repeats that matrix on every push.
Content licensing: [CC BY 4.0](LICENSE.md) · code: [MIT](LICENSE-CODE).

> **About the repository name:** the `-prompt` suffix is an artifact of how this
> repository was created; the content is the handbook itself — nothing in it is
> a prompt or generated-on-demand text.

## How to read this

- [Charter](docs/charter.md) — mission, non-goals, and the three trust levels.
- [Content standard](docs/content-standard.md) — the contract every chapter satisfies.
- [Version policy](docs/version-policy.md) — how Laravel 10→13 differences are tracked.
- [Runnable examples](docs/examples.md) — what ⚙️ means and how the harness works.
- [Decisions](docs/decisions/) — why the repository is built the way it is.

Suggested reading order: **transactions → locking → queues → idempotency →
outbox → caching → redis → eloquent → database-performance → row-level-security →
modular monolith → boundaries → eda → ddd → CQRS → api-design → authorization →
security → content-security-policy → observability → octane → testing →
deployment → online-ddl → ci-cd → mercure-streaming → preview-environments →
frankenphp**. Later chapters assume the earlier ones' vocabulary.

## Chapters

### Data

| Chapter | Trust | One-line summary |
|---|---|---|
| [Transactions](chapters/data/transactions.md) | ⚙️ | Correctness under concurrent writes: isolation levels, `afterCommit`, and the transaction boundaries Laravel doesn't draw for you. |
| [Locking](chapters/data/locking.md) | ⚙️ | Mutual exclusion without deadlocks: `Cache::lock`, DB locks, `withoutOverlapping`, and choosing scope before mechanism. |
| [Caching](chapters/data/caching.md) | ⚙️ | Fresh-enough data at scale: stampede protection, `flexible()`, invalidation strategies that don't require hope. |
| [Redis as infrastructure](chapters/data/redis.md) | ⚙️ | The decisions that matter once Redis is load-bearing: client choice, memory and eviction policy, durability expectations, failure drills. |
| [Eloquent at scale](chapters/data/eloquent.md) | ⚙️ | The three costs that dominate Eloquent under load — N+1, hydration, unbatched writes — and the guardrails that make them test failures. |
| [Database performance](chapters/data/database-performance.md) | ⚙️ | Plans over guesses: access paths, round trips, and pagination that doesn't degrade — with the assertions to keep them honest. |
| [Row-level security](chapters/data/row-level-security.md) | ⚙️ | Tenant isolation enforced at the row: structural scopes, the fail-closed posture, and the database line beneath them. |
| [Online DDL](chapters/data/online-ddl.md) | 📖 | Schema changes on big tables without stopping the app: the cheapest-first ladder, the metadata-lock trap, and the tooling for the rest. |

### Messaging & integration

| Chapter | Trust | One-line summary |
|---|---|---|
| [Queues](chapters/messaging/queues.md) | ⚙️ | Deferred work you can trust: drivers, retries, ordering, poison messages, and the worker footguns that eat weekends. |
| [Idempotency](chapters/messaging/idempotency.md) | ⚙️ | Safe retries at every boundary: keys, dedupe stores, and why "at-least-once" means your consumers are already running this pattern — badly. |
| [The transactional outbox](chapters/messaging/outbox.md) | ⚙️ | Publishing events reliably when the database is the source of truth — including the inbox half of the contract. |
| [Sagas and process managers](chapters/messaging/sagas.md) | ⚙️ | Multi-step flows with compensation: a state table, ordered steps, idempotent undos, and the failures each prevents. |
| [Message broker topologies](chapters/messaging/broker-topologies.md) | 📖 | Queue partitioning by concern and criticality, retry lanes, parking lots — the wiring plan behind queues. |
| [External brokers](chapters/messaging/external-brokers.md) | 📖 | RabbitMQ exchange patterns and Kafka stream patterns for Laravel — when the queue drivers stop being enough, and which disciplines carry over. |

### Architecture

| Chapter | Trust | One-line summary |
|---|---|---|
| [The modular monolith](chapters/architecture/modular-monolith.md) | 📖 | Well-bounded modules in one deployable: boundaries, communication rules, and the enforcement that keeps a monolith from rotting. |
| [Boundaries](chapters/architecture/boundaries.md) | 📖 | Domain / application / infrastructure as a dependency rule, translated into Laravel artifacts — and what the layering never justifies. |
| [Event-driven architecture](chapters/architecture/eda.md) | 📖 | Announcing facts instead of calling procedures: the reliability ladder, event contracts, choreography vs orchestration. |
| [Domain-driven design](chapters/architecture/ddd.md) | ⚙️ | The pragmatic DDD translation: aggregates as consistency boundaries with executable invariants, value objects, and where the ceremony stops paying. |
| [CQRS](chapters/architecture/cqrs.md) | ⚙️ | Separating read and write models *as much as the problem requires* — from simple query objects to eventual consistency. |

### Interfaces

| Chapter | Trust | One-line summary |
|---|---|---|
| [API design](chapters/interfaces/api-design.md) | ⚙️ | Resource, error, versioning, and abuse-budget contracts that survive client integrations — rate-limit windows you can execute. |
| [Authorization](chapters/interfaces/authorization.md) | ⚙️ | One decision point per rule: policies with tenant scope, visible bypasses, and denials tested as seriously as grants. |
| [Security](chapters/interfaces/security.md) | ⚙️ | Injection, mass assignment, XSS, CSRF, secrets — the disciplines Laravel doesn't force on you, each closed by a test or a gate. |
| [Content-Security-Policy](chapters/interfaces/content-security-policy.md) | ⚙️ | The browser-side containment line: an explicit policy middleware, the report-only rollout, and the header as a tested contract. |

### Quality & runtime

| Chapter | Trust | One-line summary |
|---|---|---|
| [Observability](chapters/quality/observability.md) | ⚙️ | Request context that survives the queue hop, structured logs worth searching, and the four signals that answer 3am questions. |
| [Testing the failure paths](chapters/quality/testing.md) | ⚙️ | Deterministic races, failure injection at the seam, frozen time — the toolkit every chapter's *How to test it* assumes. |
| [Octane](chapters/runtime/octane.md) | 📖 | Boot once, serve many — the state discipline that separates Octane's latency win from cross-request data leaks. |
| [Deployment](chapters/runtime/deployment.md) | 📖 | The release flow that survives overlap: expand/contract migrations, worker reloads, health gates, rehearsed rollbacks. |
| [CI/CD for Laravel](chapters/runtime/ci-cd.md) | 📖 | The pipeline that mirrors your support reality: static gates, the version matrix, evidence-backed trust marks. |
| [FrankenPHP standalone](chapters/runtime/frankenphp.md) | 📖 | One binary as web server + PHP runtime: classic vs worker mode, the embed story, and the state discipline it inherits. |
| [Streaming and real-time](chapters/runtime/mercure-streaming.md) | 📖 | Push delivery's options map — broadcasting/Reverb, SSE, Mercure — and the connection, scoping, and replay hygiene every option shares. |
| [Preview environments](chapters/runtime/preview-environments.md) | 📖 | Full disposable deployments per PR: lifecycle, data strategy, isolation contract, and the cost controls that keep them honest. |

**Trust marks:** ⚙️ = load-bearing examples executed in CI ([examples/](examples/README.md))
· 📖 = verified against official docs for stated versions · ⚠️ = contains marked
engineering judgment.

## Roadmap

The original topic list is now fully covered. Remaining ideas — smaller,
deeper, or newer than the core chapters — live here as they mature:

- **Deeper dives:** the roadmap's original deeper-dive list has been folded
  into chapters and their sections (exchange patterns and Kafka patterns →
  [external brokers](chapters/messaging/external-brokers.md); workflow
  engines → [sagas](chapters/messaging/sagas.md); trusted types →
  [content-security-policy](chapters/interfaces/content-security-policy.md);
  per-route policy registries →
  [authorization](chapters/interfaces/authorization.md); managed-platform
  DDL → [online DDL](chapters/data/online-ddl.md); supervisor templates →
  [queues](chapters/messaging/queues.md); presence →
  [streaming](chapters/runtime/mercure-streaming.md); cost attribution →
  [observability](chapters/quality/observability.md)). New chapters
  graduate from [proposal issues](.github/ISSUE_TEMPLATE/chapter-proposal.yml)
  as needs appear.

Start with a [chapter proposal](.github/ISSUE_TEMPLATE/chapter-proposal.yml);
the [content standard](docs/content-standard.md) defines done.

## Contributing

PRs welcome — start with [CONTRIBUTING.md](CONTRIBUTING.md). The short version: a
chapter proposal issue first, the [template](templates/chapter-template.md) as the
skeleton, the content standard as the bar, and ⚙️ tests for the load-bearing claims.
Small factual corrections (with a primary source) need no proposal.

By contributing you agree your contribution is licensed [CC BY 4.0](LICENSE.md)
(prose) / [MIT](LICENSE-CODE) (code) and that you can certify the
[DCO](https://developercertificate.org/) — sign commits with `git commit -s`.
