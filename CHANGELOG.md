# Changelog

All notable changes to the handbook. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: semantic (major = contract changes, minor = new chapters or
sections, patch = corrections). The commit history is the authoritative
record; this file summarizes what each release delivered and what its
review verified. The [read-through guide](docs/readthrough-guide.md)
records what each review round checked.

## [Unreleased]

## [1.1.0] — 2026-11-19

Everything since the v1.0.0 tag: the handbook's first minor release
(minor: one new chapter, per the versioning rule above).

### Added

- **Durable execution with Temporal**
  ([chapters/messaging/temporal.md](chapters/messaging/temporal.md)) — the
  34th chapter: the deterministic-replay mental model, activities as
  idempotent side effects, and the versioning discipline. Verified against
  the Temporal PHP SDK docs and keepsuit/laravel-temporal.
- [docs/reading-paths.md](docs/reading-paths.md) — scenario-based navigation,
  eight paths: first production app, multi-tenant SaaS, incident triage,
  architecture review, queue misbehavior, database scaling, hardening,
  realtime/streaming
- README: CI badge and the companion-project link to laravel-agent-bench

### Changed

- CI hardening: a least-privilege `permissions: contents: read` block for the
  whole workflow, every action pinned to its full commit SHA (version kept as
  a comment), and DCO `Signed-off-by` enforced on pull requests by a
  dedicated job

### Reviewed

- Fourth adversarial review round: 17 findings on the wave-10 chapters
  (cdc-streaming, tracing) and the quality-closure pass — all resolved; both
  chapters promoted to `reviewed`, completing the v1.0.0 contents at 33/33
- Fifth adversarial review round (the Temporal promotion gate): 14 findings,
  all resolved; temporal.md promoted to `reviewed` — 34/34 across the
  handbook
- Five rounds total to date: **91 findings, all resolved**

### Fixed

- README status count corrected to 34/34 chapters reviewed after the Temporal
  promotion

### Removed

- A stray review-agent artifact (`contrib_tree.json`, a 577KB GitHub tree
  dump); the pattern is now in `.gitignore`

## [1.0.0] — 2026-11-16

The complete initial release: every planned topic and deep dive covered,
independently reviewed, and executed.

### Added — chapters (33, six domains)

- **Data:** transactions, locking, caching, redis, eloquent,
  database-performance, row-level-security, online-ddl
- **Messaging & integration:** queues, idempotency, outbox, sagas,
  broker-topologies, external-brokers, cdc-streaming
- **Architecture:** modular-monolith, boundaries, eda, ddd, cqrs
- **Interfaces:** api-design, authorization, security,
  content-security-policy
- **Quality:** observability, testing, tracing
- **Runtime:** octane, deployment, ci-cd, frankenphp,
  mercure-streaming, preview-environments
- **docs/glossary.md** — the term index (29 entries, CI link-checked)

### Added — verification

- `examples/` — the executable harness: 49 tests × Laravel 10.50/11.57/
  12.72/13.42 (Testbench 8/9/10/11), executed in Docker and in CI
- `scripts/` — four Node gates (links, example-mapping, VERIFY markers,
  php-smoke) enforced per push
- `.github/workflows/ci.yml` — docs gates + the L10-13 matrix with Redis +
  weekly dependency-audit and external-link jobs

### Added — process and community

- charter, content standard, version policy, examples strategy, glossary,
  read-through guide, 6 ADRs
- CONTRIBUTING (review checklist, DCO), Code of Conduct, SECURITY policy,
  PR/issue templates, CODEOWNERS, Dependabot

### Quality evidence

- Three independent adversarial review rounds before the tag: 60 findings,
  all resolved (24 on waves 1-3, 18 on waves 4-6, 18 on waves 7-9). At tag
  time 31 of 33 chapters carried `status: reviewed`; the remaining two
  (cdc-streaming, tracing) were reviewed and promoted after the tag —
  see [Unreleased]
- The CI matrix caught a real framework bug on its first run: Laravel 10 +
  phpredis 6.x tag flush silent no-op (framework#54060) — now documented
  in the redis chapter and gated in the harness
- Automated security scans after every commit: 0 findings
