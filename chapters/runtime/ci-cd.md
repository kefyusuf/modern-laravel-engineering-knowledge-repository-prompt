---
title: CI/CD for Laravel
topic: ci-cd
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
---

# CI/CD for Laravel

Continuous integration for Laravel is a pipeline whose stages mirror this
handbook's concerns: static gates, a **version matrix** (the framework moves
yearly), the failure-path suite, and a deploy gate that
[deployment](deployment.md) can trust. This repository's own
[`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) is the living
example — docs lint + a Testbench matrix in one workflow.

## TL;DR

- **Use it when:** the repository has tests — CI is not optional infrastructure
  for any codebase claiming the trust levels this handbook promises.
- **Don't do it:** as one job running one PHP version against the latest
  Laravel — the pipeline's job is to catch version drift and failure-path
  regressions *before* production does.
- **The classic production incident:** a green pipeline on Laravel N ships a
  change that breaks Laravel N+1 during the upgrade window — or tests that
  only run the happy path pass while the retry/race paths
  ([testing](../quality/testing.md)) rot unseen.

## Problem

Laravel's release cadence (yearly majors, 18-month bug / 2-year security
windows — [version policy](../../docs/version-policy.md)) makes single-version
CI a liability: code that is correct today is unverified against next year's
framework. The pipeline must therefore be shaped like your support reality: a
matrix across the versions you claim to support, gates that are cheap enough to
run on every push, and deploy verification that isn't a hand-wave.

The second problem is drift between environments — "works in CI, fails in
production" — which for Laravel usually means: different config (cached env —
[Octane](octane.md) / [deployment](deployment.md) rules), different database
engines (SQLite locally, MySQL in production — engine-specific plans and
semantics differ, [database performance](../data/database-performance.md)), or
untested migrations.

## When to use it

- Every push and every PR: the fast gates (lint, static analysis, unit/failure
  paths).
- Scheduled: the full matrix, dependency audits, external link checks — the
  slow-but-valuable sweeps.
- On deploy: the health-gated release flow ([deployment](deployment.md)).

## When NOT to use it (the traps, precisely)

- **One job, one version, "it's green".** Green on Laravel 12 + PHP 8.3 says
  nothing about the Laravel 11 app three of your clients still run — or about
  PHP 8.2, where a readonly/syntax slip won't even parse.
- **A pipeline that takes 30 minutes on every push.** Cost kills the habit;
  developers stop waiting. Split: fast gates per push, matrix + sweeps on
  schedule or merge-to-main.
- **Migrations executed as a CI afterthought.** Running `migrate:fresh` on a
  scratch database proves nothing about a production upgrade — migration gates
  belong to the release flow ([deployment](deployment.md)), with
  expand/contract verified there.
- **Secrets in pipeline logs.** `env | sort` in a debug step is an incident.

## The pipeline shape

```text
per push / PR:
  1. static gates      markdownlint, style, deptrac/architecture rules,
                       link + marker checks (whatever your contract needs)
  2. analysis          PHPStan/Psalm (level budgeted, ratcheted), composer audit
  3. test matrix       the Laravel versions you support × PHP versions,
                       SQLite in-memory; failure-path suite included
  4. integration       real services where behavior depends on them
                       (Redis service container for lock/cache claims)

scheduled (nightly / weekly):
  5. sweeps            full matrix re-run, dependency audit, external links,
                       upgrade-preview branch (next Laravel major)

on release:
  6. deploy gate       the release flow with health checks + rollback
                       (deployment chapter — CI ends where the release begins)
```

Stages 1–3 are the floor; 4 matters the moment Redis/queue behavior is
load-bearing — service containers make those claims executable rather than
"we'll test it in staging".

## The simplest correct implementation

Three jobs — the static gates, the matrix, and a scheduled external-link
sweep — are the honest minimum; this repository ships exactly that
([decision 0006](../../docs/decisions/0006-ci-strategy.md)). The abridged
sketch below shows the two central jobs:

```yaml
# .github/workflows/ci.yml (the shape this repository actually runs)
jobs:
  docs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - run: npx -y markdownlint-cli2 "**/*.md"
      - run: node scripts/php-smoke.mjs
      - run: node scripts/check-links.mjs
      - run: node scripts/check-example-mapping.mjs
      - run: node scripts/check-verify-markers.mjs

  examples:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        include:
          - { laravel: "^10.0", php: "8.2", testbench: "^8.0" }
          - { laravel: "^11.0", php: "8.2", testbench: "^9.0" }
          - { laravel: "^12.0", php: "8.3", testbench: "^10.0" }
          - { laravel: "^13.0", php: "8.3", testbench: "^11.0" }
    services:
      redis:
        image: redis:7-alpine
        ports: ["6379:6379"]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring, pdo_sqlite, redis
          coverage: none
      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update --dev
          composer update --prefer-dist --no-interaction --no-progress
      - name: Run harness
        run: vendor/bin/pest --ci
```

(The sketch above is abridged: it omits the third job (the weekly
external-link sweep), the scheduled dependency-audit job, the PHPStan step
(baseline-gated, on the Laravel 12 cell), job `name:` fields, the `on:`
triggers, `concurrency`, `working-directory: examples`, the Redis
health-check options, and the composer-cache step — all of which are in the
real [ci.yml](../../.github/workflows/ci.yml).)

What each piece is for:

- **`fail-fast: false`** — a Laravel 13 break must not hide the Laravel 10
  result (and vice versa); the matrix exists to show all failures at once.
- **One Testbench major per Laravel major** (8→10, 9→11, 10→12, 11→13) — the
  mapping is documented upstream; the matrix cell pins it explicitly.
- **The Redis service container** — lock/cache/queue-behavior claims execute
  for real ([redis](../data/redis.md)); tests without the service skip
  gracefully on laptops via `redisRequired()`.
- **`pest --ci`** — deterministic output for the runner, no progress
  overwrites.

## What production requires

### Caching without lying

Cache `~/.composer/cache` and `~/.cache/pip`-style paths keyed on
`composer.lock` — a matrix of four cells without dependency caching burns
runner minutes on every push. But never cache `vendor/` itself: stale autoload
dumps and platform-specific binaries are the ghost-debug of the month.

### The upgrade preview

A scheduled job (or a branch) that installs **next Laravel major + its
Testbench** and runs the matrix — the earliest possible signal for the version
policy's yearly cycle. Failing in a preview job is free; discovering the same
break in an upgrade sprint is a project.

### Evidence, not vibes

- Every claim this handbook makes at ⚙️ maps to a test the pipeline executes
  ([examples strategy](../../docs/examples.md)) — the pipeline is the proof
  machine, and the README's trust marks reference its runs.
- Deployment is gated by the same pipeline's artifacts: build once, deploy the
  artifact, health-gate it ([deployment](deployment.md) release flow) — CI
  passing is the *entry condition* to a deploy, never the deploy itself.
- Secrets come from the runner's secret store, never from committed env files;
  a leaked secret is rotated, not deleted from history.

## What usually goes wrong

1. **The single-version monoculture.** *Root cause:* matrix feels expensive.
   *Detection:* upgrade attempts that turn into archaeology. *Fix:* the matrix
   as the default — four cells × a fast suite is minutes with caching.
2. **Flaky tests normalized.** *Root cause:* sleep-based concurrency tests and
   timing assertions ([testing](../quality/testing.md)). *Detection:* retry
   buttons in the CI UI; "rerun passed" culture. *Fix:* deterministic
   interleave tests; quarantine-with-deadline for stragglers.
3. **Caches serving stale platforms.** *Root cause:* vendor/ or keyless caches
   shared across PHP versions. *Detection:* "works on my runner" bugs;
   autoload/classmap mismatches. *Fix:* lock-file-keyed composer cache only.
4. **Migration drift.** *Root cause:* `migrate:fresh` in CI against a schema
   that production reached through 200 incremental migrations — fresh-schema
   only. *Detection:* production "column exists"/"no such column" after
   deploys that CI blessed. *Fix:* run the full migration chain from zero on
   the matrix database engine (not SQLite when production is MySQL), plus the
   expand/contract gate in staging ([deployment](deployment.md)).
5. **Secret hygiene as an afterthought.** *Root cause:* convenient debug
   output. *Detection:* pipeline log review — or worse, an external report.
   *Fix:* secret scanning in the static stage; rotation runbook.
6. **The pipeline that only the pipeline trusts.** *Root cause:* tests pass in
   CI but nobody can run them locally (missing services, hardcoded envs).
   *Detection:* "just push and see" development. *Fix:* env-driven service
   config with graceful skips (this harness's `redisRequired()` pattern) —
   local near-parity is a feature.

## How to test it

- **The pipeline itself is under test** every time it runs — treat workflow
  changes as code changes: they get PRs, review, and the same gates.
- **Skip lists are version contracts:** a test that skips on Laravel 10 but
  runs on 12 is asserting a version claim — the skip reason must match the
  chapter's version table, and CI makes the matrix enforce it (this harness's
  flexible/Context skips are exactly that, verified across all four cells).
- **Pipeline failures are incidents:** a red main-branch pipeline is
  all-hands-on-deck or the matrix stops meaning anything.

## How to operate it

- **Runner budget:** matrix × cache × concurrency limits — watch minutes; a
  pipeline that becomes expensive becomes optional, which is worse than
  expensive.
- **Branch protection:** required checks = the matrix + static gates; direct
  pushes to main bypassing CI is how trust marks start lying.
- **3am runbook:** red pipeline on main → fix-forward (revert is a fix);
  matrix cell red only on one version → that's a version claim to document or
  a real compatibility bug — either way it's a chapter note
  ([version policy](../../docs/version-policy.md) lifecycle).

## How it scales

- **Sharding the suite** (parallel test files across runners) keeps the
  per-push loop fast as the codebase grows; the matrix multiplies whatever you
  don't shard.
- **Self-hosted runners** enter when cost or network posture demands — with
  the same job definitions; the pipeline's shape shouldn't change.
- **The end state** is boring: every push verified across the support matrix,
  every release gated by health, every claim in the handbook backed by a test
  the pipeline executes — and nobody remembers when CI was optional.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Testbench major for the matrix cell | 8 | 9 | 10 | 11 |
| `php artisan test` (parallel via `--parallel`) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP (per matrix cell) | 8.1 | 8.2 | 8.2 | 8.3 |

The pipeline shape is tool-agnostic (GitHub Actions here; GitLab/Jenkins run
the same stages) — the Laravel-specific input is the Testbench mapping and the
yearly matrix bump ([version policy](../../docs/version-policy.md)).

## Related

- [Deployment](deployment.md) — CI ends where the release flow begins; the
  health gate and expand/contract contract.
- [Testing the failure paths](../quality/testing.md) — what the pipeline
  executes; determinism rules that keep it trustworthy.
- [Version policy](../../docs/version-policy.md) — the matrix's source of
  truth and its quarterly lifecycle.
- [The modular monolith](../architecture/modular-monolith.md) — deptrac in CI
  as the boundary enforcer.
- [Deployment](deployment.md) — the release flow CI hands off to: deploy
  markers, health gates, and the post-deploy watch that closes the loop.
- Roadmap: preview environments, merge queues.
