# ADR 0006: CI strategy — three gates

- **Status:** accepted (2026-09-19)
- **Scope:** CI strategy

## Context

The trust model (charter) makes three claims that must be mechanically enforced, or
they decay: examples execute, links resolve, and no unverified claims ship.

## Decision

GitHub Actions, one workflow, three jobs:

1. **`docs`** — markdownlint-cli2 (style contract), internal link + anchor checker
   (`scripts/check-links.mjs`), `@example-id` ↔ chapter-tag mapping
   (`scripts/check-example-mapping.mjs`), `VERIFY`-marker gate on `main`
   (`scripts/check-verify-markers.mjs`).
2. **`examples`** — the Testbench/Pest harness across the version matrix
   (Laravel 10/11/12/13 × PHP 8.2/8.3, SQLite + Redis service container).
3. **`external-links`** — scheduled weekly lychee run for external URLs (not per-PR:
   slow and flaky).

Branch protection intent: `docs` + `examples` required on `main`; the `VERIFY` marker
gate applies to `main` only (drafts may carry markers).

## Consequences

- External-link rot is caught weekly, not per-PR; internal integrity is caught
  per-PR.
- The matrix consumes CI minutes (4 jobs × Redis service); acceptable at this scale.
- Local near-parity: markdownlint + the three Node scripts run locally; Pest requires
  PHP locally, otherwise CI is the executor.
