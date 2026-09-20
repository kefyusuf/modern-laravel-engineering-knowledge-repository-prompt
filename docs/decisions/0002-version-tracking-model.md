# ADR 0002: Version tracking — per-chapter tables, reviewed-against stamps, VERIFY markers

- **Status:** accepted (2026-09-19)
- **Scope:** versioning strategy

## Context

Laravel releases yearly (10→13 at time of writing) and behavior differs across versions
in ways that silently invalidate examples (new APIs, changed defaults, new PHP minimums).
Mixing versions is the most common credibility failure of Laravel content.

## Options considered

1. **One branch/edition per Laravel version** — clean reading per version, but N-way
   content drift and N× maintenance. Rejected.
2. **Only document current Laravel** — simplest, but abandons the intermediate → senior
   audience, most of whom maintain apps on older supported versions. Rejected as sole
   strategy.
3. **Per-chapter version-difference tables + `reviewed-against` stamp + `VERIFY`
   markers enforced by CI** — one canonical document, differences localized where they
   matter, staleness made visible, unverifiable claims made unmergeable.

## Decision

Option 3, detailed in [version-policy.md](../version-policy.md). Content baseline =
lowest security-supported Laravel (12 as of 2026-09); Laravel 10/11 recorded where the
difference matters historically.

## Consequences

- Every version claim must name its release; unverifiable claims carry a CI-failing
  marker instead of silently persisting.
- Quarterly re-verification is a manual process with an automated reminder; it can lag
  a release by days. Accepted.
- Chapter frontmatter `reviewed-against` doubles as staleness signal.

## Self-review

The mechanism is verifiable (marker CI check, harness matrix), adds no abstraction, and
does not require trusting any single author's memory.
