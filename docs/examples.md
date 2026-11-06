---
title: Runnable example strategy
---

# Runnable example strategy

Chapters are read; examples are **executed**. This document defines the mechanism that
keeps them from drifting apart.

## Design

- **Chapters contain readable, paste-able application code** — written for a reader with
  a real Laravel app open, not for a test runner.
- **The harness proves the claims.** [`examples/`](../examples/README.md) is a minimal
  [Orchestra Testbench](https://github.com/orchestral/testbench) project with Pest
  tests. Each test encodes one load-bearing behavioral claim (a race, a commit order, a
  retry path) and maps to a tagged example block via `@example-id`.
- **The mapping is enforced both ways** by CI: every tagged example block in a chapter
  must exist as a harness test, and every harness `@example-id` must be referenced by a
  chapter. No orphan tests quietly testing nothing, no examples claiming execution that
  isn't run.

Why Testbench rather than a full Laravel app:

| Option | Verdict |
|---|---|
| Full Laravel app in-repo | Heaviest install; drags frontend/build tooling into a docs repo; matrix jobs 3× slower. |
| Plain PHP scripts | Cannot verify *framework* behavior (queue retries, cache lock semantics), which is exactly the part that goes wrong. |
| **Orchestra Testbench** | Boots the real framework, supports one Testbench major per Laravel major (8→10, 9→11, 10→12, 11→13), purpose-built for version matrices. |

## What gets a ⚙️ test

Not every snippet. A claim earns a test when being wrong in production is expensive and
the behavior is observable in a test process:

- concurrency and ordering semantics (`DB::afterCommit`, `withoutOverlapping`),
- lock behavior (acquire/block/expire/ownership),
- idempotency-key dedupe under retries,
- outbox dispatch timing (inside vs. after commit),
- cache semantics (`flexible`, atomic locks, stampede protection).

The "bad implementation" ladder step is usually **not** executed — its failure mode is
often data-dependent or timing-dependent; asserting flaky behavior makes CI itself
untrustworthy. Where a race can be reproduced deterministically (e.g. two simulated
workers on SQLite), it *is* encoded as a test and marked as such.

## Environment matrix

CI runs the harness per [version policy](version-policy.md):

| Job | PHP | Laravel | Testbench | Database / extras |
|---|---|---|---|---|
| L10 | 8.2 | ^10.0 | ^8.0 | SQLite + Redis service |
| L11 | 8.2 | ^11.0 | ^9.0 | SQLite + Redis service |
| L12 | 8.3 | ^12.0 | ^10.0 | SQLite + Redis service |
| L13 | 8.3 | ^13.0 | ^11.0 | SQLite + Redis service |

Version-specific tests use `markTestSkipped` with a reason when a behavior does not
exist on that version (the chapter's version table must agree with the skip list).

## Local usage

```bash
cd examples
composer install
./vendor/bin/pest
# filter by chapter claim:
./vendor/bin/pest --group=outbox
```

Redis-dependent tests auto-skip when no Redis is reachable, so the suite degrades
gracefully on a laptop without services.

## First execution (recorded 2026-11)

**Executed.** The suite runs locally in Docker — `php:8.3-cli` + Composer + a
`redis:7-alpine` service — across the full matrix (re-run after wave 4):

| Cell | Result |
|---|---|
| Laravel 10.50.4 / Testbench 8 / PHP 8.3 | 43 passed + 2 skips (`flexible` ❌ on 10, `Context` ❌ on 10 — gates match the chapters' version tables) |
| Laravel 11.57.1 / Testbench 9 / PHP 8.3 | **45/45 passed** |
| Laravel 12.71.0 / Testbench 10 / PHP 8.3 | **45/45 passed** |
| Laravel 13.40.0 / Testbench 11 / PHP 8.3 | **45/45 passed** |

The first execution surfaced and fixed five real harness defects (`Pest.php`'s
`->in()` scoping, a fixture missing `ShouldQueue`, the failed-jobs
cross-connection trap, tag read-set semantics, and Pest 2/3 API differences) plus
three framework/version findings recorded in the relevant chapters. CI repeats
this matrix on every push once the repository has a remote.
