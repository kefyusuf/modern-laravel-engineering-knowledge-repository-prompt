# Modern Laravel Engineering

**A production engineering handbook for intermediate → senior Laravel engineers.**
Not a syntax tutorial: every chapter answers what problem a technique solves, when to
avoid it, what a production implementation requires, how it is tested and operated,
and exactly which Laravel/PHP versions change the answer.

**Status: initial build (2026-09).** Chapters marked ⚙️ have their load-bearing
examples encoded as executable tests ([details](docs/examples.md)); until the first
green CI run this means *encoded*, not *executed*. Content licensing: [CC BY 4.0](LICENSE.md)
· code: [MIT](LICENSE-CODE).

## How to read this

- [Charter](docs/charter.md) — mission, non-goals, and the three trust levels.
- [Content standard](docs/content-standard.md) — the contract every chapter satisfies.
- [Version policy](docs/version-policy.md) — how Laravel 10→13 differences are tracked.
- [Runnable examples](docs/examples.md) — what ⚙️ means and how the harness works.
- [Decisions](docs/decisions/) — why the repository is built the way it is.

Suggested reading order for the first wave: **transactions → locking → queues →
idempotency → outbox → caching → modular monolith → CQRS**. Later chapters assume the
earlier ones' vocabulary.

## Chapters

### Data

| Chapter | Trust | One-line summary |
|---|---|---|
| [Transactions](chapters/data/transactions.md) | ⚙️ | Correctness under concurrent writes: isolation levels, `afterCommit`, and the transaction boundaries Laravel doesn't draw for you. |
| [Locking](chapters/data/locking.md) | ⚙️ | Mutual exclusion without deadlocks: `Cache::lock`, DB locks, `withoutOverlapping`, and choosing scope before mechanism. |
| [Caching](chapters/data/caching.md) | ⚙️ | Fresh-enough data at scale: stampede protection, `flexible()`, invalidation strategies that don't require hope. |

### Messaging & integration

| Chapter | Trust | One-line summary |
|---|---|---|
| [Queues](chapters/messaging/queues.md) | ⚙️ | Deferred work you can trust: drivers, retries, ordering, poison messages, and the worker footguns that eat weekends. |
| [Idempotency](chapters/messaging/idempotency.md) | ⚙️ | Safe retries at every boundary: keys, dedupe stores, and why "at-least-once" means your consumers are already running this pattern — badly. |
| [The transactional outbox](chapters/messaging/outbox.md) | ⚙️ | Publishing events reliably when the database is the source of truth — including the inbox half of the contract. |

### Architecture

| Chapter | Trust | One-line summary |
|---|---|---|
| [The modular monolith](chapters/architecture/modular-monolith.md) | 📖 | Well-bounded modules in one deployable: boundaries, communication rules, and the enforcement that keeps a monolith from rotting. |
| [CQRS](chapters/architecture/cqrs.md) | ⚙️ | Separating read and write models *as much as the problem requires* — from simple query objects to eventual consistency. |

**Trust marks:** ⚙️ = load-bearing examples executed in CI ([examples/](examples/README.md))
· 📖 = verified against official docs for stated versions · ⚠️ = contains marked
engineering judgment.

## Roadmap

Chapters below are **wanted, not written** — no stubs on purpose
([decision 0005](docs/decisions/0005-first-chapters.md)). Open an issue before
drafting; the [content standard](docs/content-standard.md) defines done.

- **Architecture:** domain-driven design in Laravel · domain/application/infrastructure
  boundaries · event-driven architecture
- **Data:** Redis as infrastructure · database performance · Eloquent at scale
- **Interfaces & security:** API design · security · authorization
- **Quality:** observability · testing beyond the happy path
- **Runtime:** Octane · FrankenPHP · deployment · CI/CD for Laravel

## Contributing

PRs welcome — start with [CONTRIBUTING.md](CONTRIBUTING.md). The short version: a
chapter proposal issue first, the [template](templates/chapter-template.md) as the
skeleton, the content standard as the bar, and ⚙️ tests for the load-bearing claims.
Small factual corrections (with a primary source) need no proposal.

By contributing you agree your contribution is licensed [CC BY 4.0](LICENSE.md)
(prose) / [MIT](LICENSE-CODE) (code) and that you can certify the
[DCO](https://developercertificate.org/) — sign commits with `git commit -s`.
