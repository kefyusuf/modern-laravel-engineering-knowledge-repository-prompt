# Changelog

All notable changes to the handbook. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: semantic (major = contract changes, minor = new chapters or
sections, patch = corrections). The commit history is the authoritative
record; this file summarizes what each release delivered and what its
review verified. The [read-through guide](docs/readthrough-guide.md)
records what each review round checked.

## [Unreleased]

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
- **docs/reading-paths.md** — scenario-based navigation (six paths)

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

- Four independent adversarial review rounds: 77 findings, all resolved
  (24 on waves 1-3, 18 on waves 4-6, 18 on waves 7-9, 14 on the Temporal
  promotion gate)
- The CI matrix caught a real framework bug on its first run: Laravel 10 +
  phpredis 6.x tag flush silent no-op (framework#54060) — now documented
  in the redis chapter and gated in the harness
- Mimosa security scans after every commit: 0 findings
