---
title: Version policy
---

# Version policy

The repository tracks **Laravel 10 → 13** and the PHP versions they support. This
document defines how version-sensitive claims are recorded, verified, and retired.

## Current support reality (stated as of 2026-09)

| Version | Released | Bug fixes until | Security fixes until | Status 2026-09 |
|---|---|---|---|---|
| Laravel 10 | 2023-02 | 2024-08 | 2025-02 | **EOL** — historical reference only |
| Laravel 11 | 2024-03 | 2025-09 | 2026-03 | **EOL** — historical reference only |
| Laravel 12 | 2025-02 | 2026-08 | 2027-02 | security-fixes only |
| Laravel 13 | 2026-Q1 | 2027-08 | 2028-02 | current release |

Laravel's policy is 18 months of bug fixes / 2 years of security fixes per release
([official support policy](https://laravel.com/docs/releases#support-policy)). This
table is re-verified at the start of each quarter (CI reminder issue) and the
"stated as of" date is updated in the same PR.

## Rules for content

1. **Baseline**: examples and recommendations target the lowest Laravel version still
   receiving security fixes, and note the current release's differences. As of
   2026-09 that baseline is **Laravel 12**, with Laravel 13 deltas called out.
2. **Historical coverage**: where behavior differs in Laravel 10/11 (e.g. an API
   introduced in 11.9), the [version table](content-standard.md#version-table-format)
   records it — many production codebases are older than the support window.
3. **Version citations are mandatory** for:
   - the release in which an API was introduced (e.g. `Cache::flexible()` — 11.9),
   - changed defaults, changed semantics, changed minimum PHP,
   - anything a reader would migrate code over.
4. **Primary sources only** for version claims: official Laravel docs
   (`laravel.com/docs/<version>`), the framework changelog/source
   (`github.com/laravel/framework`), or release announcements. Blog posts may inspire a
   claim but never carry one alone.
5. **PHP versions**: chapters state PHP minimums only where they change the answer
   (e.g. readonly properties, fibers in Octane). Known minimums: Laravel 10 → PHP
   8.1, Laravel 11 → 8.2, Laravel 12 → 8.2, Laravel 13 → 8.3.
6. **No forward-looking claims** about unreleased behavior. If a chapter must reference
   upcoming work, it goes in the roadmap, not the chapter body.

## The `VERIFY` marker

A claim that *should* be verifiable but was not yet checked against a primary source
carries:

```markdown
<!-- VERIFY: exact minor version that changed Queue::retryUntil semantics -->
```

Rules:

- Markers are **temporary**. CI fails if any `VERIFY` marker exists on `main`
  ([scripts/check-verify-markers.mjs](../scripts/check-verify-markers.mjs)). Resolving a
  marker = deleting it after citing the primary source in the same diff.
- Markers are allowed in PRs and in `status: draft` chapters.
- A marker older than 30 days on a draft chapter blocks that chapter's promotion to
  `reviewed`.

## Version review lifecycle

- Each chapter's frontmatter `reviewed-against` names the versions checked and the
  date. The quarterly reminder issue asks maintainers to re-run version review on any
  chapter older than two Laravel minor releases.
- When Laravel N+1 is released: open issues for affected chapters (from a `grep` of the
  changelog against chapter topics), update tables, re-run the harness matrix with the
  new Testbench major.
