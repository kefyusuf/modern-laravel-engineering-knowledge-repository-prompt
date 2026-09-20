# ADR 0001: GitHub-native Markdown, no site generator

- **Status:** accepted (2026-09-19)
- **Scope:** information architecture

## Context

Content must be trustworthy and reviewable. Open-source engineering references fail
when the reading experience and the contribution experience diverge.

## Options considered

1. **Plain Markdown on GitHub** — every claim is a reviewable diff; zero build step to
   read or to contribute; cross-links are relative paths.
2. **VitePress / MkDocs** — nicer navigation, versioned docs UI, search.
3. **mdBook** — book metaphor, single binary build.

## Decision

Option 1. The repository is read on GitHub (or any Markdown viewer) as-is.

## Consequences

- Contribution friction is minimal: a PR is the unit of trust.
- No docs build means **no automatic link checking** — we must ship our own
  (internal anchor checker in `scripts/`, run by CI).
- Navigation is carried by the README index and per-chapter `Related` sections rather
  than a sidebar; if the IA outgrows this, a static-site layer can be added *on top*
  of the same Markdown without changing authoring contracts.
- Markdownlint config becomes part of the contract (`.markdownlint-cli2.jsonc`).

## Self-review

Aligned with "trustworthiness over volume": reviewable diffs are the trust mechanism.
No existing invariant violated (greenfield). Verification is explicit (CI link/marker
checks) rather than assumed.
