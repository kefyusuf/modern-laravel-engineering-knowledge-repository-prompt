# ADR 0003: Example harness — Orchestra Testbench + Pest, chapter↔test ID mapping

- **Status:** accepted (2026-09-19)
- **Scope:** runnable example strategy, CI

## Context

"Runnable examples" that are never run drift into fiction. The repository needs its
behavioral claims executed somewhere, against multiple Laravel versions, without
turning a documentation repo into an application repo.

## Options considered

1. **No execution** — cheapest; rejects the core trust mandate. Rejected.
2. **Full Laravel app in-repo** — realistic, but heavy install, frontend tooling noise,
   slow matrix. Rejected.
3. **Orchestra Testbench + Pest harness in `examples/`** — boots the real framework,
   one Testbench major per Laravel major, minimal footprint.
4. **Pest plug-and-play standalone** — cannot exercise framework behavior
   (queues, cache locks, transactions), which is precisely the error-prone surface.

## Decision

Option 3. Mapping contract:

- Chapter blocks tagged `<!-- example: <topic>/<slug> -->` directly above the fence.
- Harness tests carry `@example-id <topic>/<slug>` docblocks.
- `scripts/check-example-mapping.mjs` validates both directions in CI.
- See [examples.md](../examples.md) for the matrix and skip semantics.

## Consequences

- Tests are the source of truth: chapter and test disagree → chapter is wrong.
- Authors must write both prose and tests; the PR template makes this explicit.
- Redis-dependent tests auto-skip without Redis; the skip list must stay consistent
  with the chapter version tables.
- Until the first green CI run, ⚙️ means "encoded", not "executed" — stated on README.

## Self-review

Smallest mechanism that makes the central promise mechanically checkable. No public
API impact (docs repo). Complexity contained in one directory; rollback = delete
`examples/` and the CI job.
