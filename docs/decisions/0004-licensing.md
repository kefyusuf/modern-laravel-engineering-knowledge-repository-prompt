# ADR 0004: Dual licensing — docs CC BY 4.0, code MIT

- **Status:** accepted (2026-09-19)
- **Scope:** open-source model

## Context

The repository is two things: prose (should be usable, remixable, shareable) and code
(harness + examples, should be reusable in any project, including commercial).

## Options considered

1. **MIT for everything** — prose under MIT is unusual and poorly understood; CC
   licenses are the ecosystem norm for written material.
2. **CC BY-SA 4.0 for everything** — share-alike complicates reuse of examples inside
   proprietary codebases.
3. **Dual: CC BY 4.0 (`LICENSE.md`) for prose, MIT (`LICENSE-CODE`) for code** — the
   split used by major documentation projects.

## Decision

Option 3. Boundary: everything outside `examples/` and `scripts/` is prose (CC BY
4.0); inside them, MIT. Attribution convention: link back to the chapter URL.

## Consequences

- Contributors sign nothing extra; PRs imply both licenses for the respective paths.
- Attribution is lightweight but expected — stated in the README.
