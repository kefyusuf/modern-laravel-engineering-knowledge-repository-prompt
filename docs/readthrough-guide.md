---
title: Read-through guide
---

# Read-through guide

The maintainer's read-through is the **final sign-off** on top of the
mechanical gates: CI green, three adversarial review rounds, the executed
matrix. This guide exists so the read-through is comparable over time —
each section tells the reader what to check, what to distrust, and what
evidence backs the claims. Written for the 2026-11 read-through; re-run it
per major content wave and at the quarterly version re-verification.

## The sign-off checklist (per chapter)

1. **The TL;DR decision** — would you make the decision the TL;DR tells
   you to make? A chapter whose decision box you disagree with is a
   content finding, not a style note.
2. **The trust marks** — ⚙️ chapters: open the linked harness test file
   and confirm the mapped test asserts what the chapter claims; 📖
   chapters: spot-check two version-table rows against the primary
   source; ⚠️ items: is the caveat still true?
3. **The version table** — every non-obvious cell traces to a primary
   source; the intro/outro says what was verified and when.
4. **"When NOT to use it"** — would you really reject the technique in
   those situations? Strawman rejections are the most common content
   defect.
5. **The failure modes** — each reads as a story you've seen or could
   see; detection signals are observable; fixes are the chapters' own.
6. **Cross-links resolve to the RIGHT chapter** — the link checker
   verifies existence, not intent; the reader verifies intent.

## Per-chapter focus (what to scrutinize)

- **transactions** — the nested-transaction/savepoint semantics and the
  `afterCommit` wording ("dispatch-order guarantee, not reliability").
- **locking** — the owner-checked `release()` rules per driver; the
  lock-scope checklist.
- **caching** — the tag-driver contract rows; the `flexible()` 11.23
  boundary.
- **redis** — the **Laravel 10 + phpredis tag-flush bug row** (framework
  #54060, caught by this handbook's own CI matrix — the strongest
  evidence-backed finding in the handbook); the eviction-policy rules.
- **eloquent** — the L12 namespace move and the `Builder::hydrate()`
  single-row guard gap (both verified in framework source).
- **database-performance** — the MySQL FK auto-index correction (InnoDB
  auto-creates with the constraint; PostgreSQL doesn't).
- **queues** — the `retry_after` vs `$timeout` budget and the
  failed-jobs cross-connection trap (found first-hand in the harness).
- **idempotency** — the layer model (natural → atomic → unique-index →
  cache-as-fast-path) and the same-transaction rule.
- **outbox** — the relay's at-least-once analysis and the mark/dispatch
  race on non-transactional queues.
- **sagas** — the compensation ledger's atomic undo (review 3 fix);
  "when to reach for an engine" — is the bar honest?
- **broker-topologies / external-brokers** — the package-pin table
  (verified against packagist 2026-11-15); the RabbitMQ/Kafka "when"
  boundaries.
- **modular monolith / boundaries** — the numbered rules; would you
  enforce them, or are any ceremonial?
- **CQRS** — the spectrum levels and the `dispatchCommand` ❌ row.
- **api-design / authorization** — the rate-limit window claims and the
  `can:` middleware argument forms (review-3 fix).
- **security / content-security-policy** — the injection demo's
  two-sidedness; Trusted Types rollout.
- **observability / tracing** — the propagation claims and the sampling
  discipline; the cost-attribution section.
- **online-ddl / cdc-streaming / frankenphp / mercure-streaming /
  preview-environments** — the engine/infrastructure claims are
  docs-verified but **not executed**; treat them as the least-verified
  tier and spot-check against upstream docs.

## Known-remaining caveats (read with these in mind)

1. **PHPStan baseline: 30 recorded findings.** The gate is "no NEW
   findings"; the recorded ones are type-statistical (DB::table row
   property access, facade aliases, dynamic demo properties) — reviewed
   once, harmless, but they are not "verified clean".
2. **Docs-verified tier:** frankenphp, online-ddl, cdc-streaming,
   broker-topologies, and the architecture chapters' structural claims
   are verified against documentation, not executed. The engine/infra
   behaviors (SKIP LOCKED concurrency, Mercure replay, managed-platform
   DDL) need staging drills to be fully trusted.
3. **The ⚠️ rows:** deliberate version caveats (per-route CSP registries,
   managed-platform DDL, OTel package support per release) — each names
   its verification path.
4. **The harness's sqlite scope:** the matrix proves logic, not engines —
   SKIP LOCKED, row-lock blocking, and PostgreSQL RLS are
   staging-verified per engine ([examples.md](examples.md) states this).

## The assessment (2026-11 read-through, by the build agent)

- **Strongest:** the executed tier — transactions, locking, idempotency,
  outbox, sagas, queues, redis, eloquent, database-performance,
  row-level-security, api-design, authorization, security, testing,
  cqrs — 15 chapters whose load-bearing claims run in CI, several
  carrying findings *first-hand* (the phpredis tag-flush bug, the
  failed-jobs connection trap, the hydrate single-row gap).
- **Solid:** observability, content-security-policy, ci-cd — tested
  claims + docs-verified posture.
- **Least-verified tier:** the architecture chapters (boundaries, ddd,
  eda, modular-monolith — prose+structure claims), online-ddl,
  cdc-streaming, frankenphp, mercure-streaming, preview-environments —
  docs-verified and reviewed, with execution left to staging drills by
  design.

## Promotion policy

- A chapter is `reviewed` when: three review rounds have covered it, all
  findings are resolved, the matrix is green, and a maintainer
  read-through (this guide) has touched it.
- Promotion is per content wave: the 2026-11 read-through signed off the
  build through wave 10; future waves re-run this guide per new chapter
  before promotion.
- Demotion is expected on staleness: a chapter older than two Laravel
  majors without re-verification drops back to `draft` per the
  [version policy](version-policy.md).
