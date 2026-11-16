# Security Policy

## Scope

This repository is a documentation handbook with a small verification
harness. Two attack surfaces exist:

1. **The harness code** (`examples/`, `scripts/`) — PHP and Node scripts
   executed by contributors and CI.
2. **The published guidance itself** — a security claim in a chapter that is
   wrong (e.g. an unsafe code pattern presented as safe) is a security issue
   for every reader who trusts it. See the
   [security chapter](chapters/interfaces/security.md).

Vulnerabilities in Laravel or its dependencies belong to their upstream
projects — but a *chapter's guidance* that leads readers into a known
upstream vulnerability is ours to fix.

## Reporting a vulnerability

- **GitHub private vulnerability reporting** (the repository's Security tab →
  "Report a vulnerability") — preferred; visible only to maintainers.
- For guidance-content issues that are not a classic vulnerability, open a
  regular [correction issue](.github/ISSUE_TEMPLATE/correction.yml) — public
  correction is the fix for wrong documentation.

Please include: the file/section, the claimed behavior, the actual behavior,
and (for harness code) reproduction steps.

## Response process

1. Acknowledge within **72 hours**.
2. Assess severity: does the flaw put followers of the guidance at risk, or
   is it contained to this repository's own tooling?
3. Fix content findings in the affected chapter(s) the same week; the
   correction ships in the next push and is noted in the commit message.
4. For harness/tooling findings: patch, verify against the CI matrix, and
   only then disclose details.

## Supported versions

| Scope | Support |
|---|---|
| `main` branch (docs + harness) | ✅ |
| Older commits/tags | ❌ — the handbook is corrected forward |
| Laravel versions covered by the [matrix](https://github.com/kefyusuf/modern-laravel-engineering-knowledge-repository-prompt/actions) | ✅ claims verified per cell |
| Laravel versions outside the [support windows](docs/version-policy.md) | ❌ — historical reference only |

## What we ask of readers

Do not test security guidance claims against systems you do not own or have
written authorization for — the injection demonstrations in the
   [security](chapters/interfaces/security.md) chapter are deliberately
benign and self-contained, and the harness runs against in-memory SQLite.
