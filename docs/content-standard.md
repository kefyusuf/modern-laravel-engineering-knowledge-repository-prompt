---
title: Content standard
---

# Content standard

This document is the **contract** every chapter must satisfy. A chapter that does not
meet it is not merged. Reviewers use it as a checklist; the PR template mirrors it.

## Chapter anatomy

Every chapter is one Markdown file at `chapters/<domain>/<topic>.md`, starting with
YAML frontmatter:

```yaml
---
title: Transactions                # short human title
topic: transactions                # unique slug, matches example IDs
status: reviewed                   # draft | reviewed
reviewed-against: "Laravel 13.x & 12.x docs, 2026-09"  # what was actually checked, and when
testbed: examples/tests/TransactionsExamplesTest.php   # omit if the chapter has no ⚙️ examples
---
```

Rules:

- `reviewed-against` must name **specific versions and a date**. "Latest Laravel" is not
  acceptable.
- Section headings below are **fixed** (readers navigate by muscle memory). A section
  that genuinely does not apply may be replaced by one sentence saying why — it may not
  be silently dropped.

```markdown
# <Title>

<2–4 sentence framing: what this is, and the one-line "shape" of the decision.>

## TL;DR
## Problem
## When to use it
## When NOT to use it
## The simplest correct implementation
## What production requires
## What usually goes wrong
## How to test it
## How to operate it
## How it scales
## Version differences
## Related
```

### Section content rules

| Section | Must contain | Must not contain |
|---|---|---|
| `TL;DR` | The decision box: use-it condition, don't-use-it condition, the one configuration mistake that causes the classic production incident. | Code over ~15 lines. |
| `Problem` | The concrete failure mode in production terms, not textbook definitions. | History of the pattern. |
| `When to use it` | Concrete signals ("you have N of X", "your p99 is…"). | Restating the problem. |
| `When NOT to use it` | At least 3 real situations, with the alternative to use instead. | strawman rejections. |
| `Simplest correct implementation` | [Minimal example](#example-quality-ladder) with the *least* code that is still correct. Anything removed must degrade correctness, not polish. | "Production" concerns (retries, metrics, config). |
| `What production requires` | [Production example](#example-quality-ladder) + the non-code requirements: config, infrastructure, monitoring hooks, failure drills. | Hand-waving ("add monitoring"). Name the metric, the log channel, the alert. |
| `What usually goes wrong` | Realistic failure narratives, each with: root cause → detection signal → fix. Ordered by how often they bite. | Hypotheticals nobody has hit. |
| `How to test it` | How to test *this concern* (not general testing advice): the race, the failure injection, the assertion that would catch the bug. Link ⚙️ tests. | Testing pyramid lectures. |
| `How to operate it` | Day-2 reality: dashboards, commands, runbooks, what to check at 3am. | Setup instructions that belong in `What production requires`. |
| `How it scales` | The load ceiling and what breaks first; the next lever when it does. | "It scales well." |
| `Version differences` | The [version table](#version-table-format). If nothing differs across relevant versions, state which behavior is stable since which version. | Unverifiable claims. |
| `Related` | Cross-links with a one-line "why you'd care" per link. | Link dumps. |

## Example quality ladder

Where appropriate — mandatory for patterns with a known anti-pattern — examples follow
this escalation:

1. **Bad implementation** — realistic code someone would actually write.
2. **Why it fails** — the specific mechanism (race, lock ordering, memory growth), not
   "this is bad practice".
3. **Minimal implementation** — smallest correct version.
4. **Production-safe implementation** — config, retries, observability, failure paths.
5. **Tests** — what proves the property (link to the ⚙️ harness test where it exists).
6. **Operational considerations** — what it looks like in production.

## Example tagging

Every code block that is backed by an executed test carries an HTML comment **directly
above the fence**:

````markdown
<!-- example: outbox/dispatch-after-commit -->
```php
````

- Example IDs are `<topic>/<kebab-case-name>`; `topic` matches the frontmatter `topic`.
- The ID must exist in the chapter's `testbed` file as a test docblock
  `@example-id outbox/dispatch-after-commit`.
- CI checks the mapping in both directions. A chapter may ship untagged examples (e.g.
  the "bad" ladder step) — but every **production-safe** example is tagged.
- The `examples/` test is the source of truth: if the test and the chapter disagree, the
  chapter is wrong until re-verified.
- **Exception (architecture chapters):** structural examples — module layouts,
  interface sketches, namespace conventions — carry no behavioral claim and may be
  untagged. Any architecture example that *does* assert behavior (e.g. "events fired in
  one module are handled by another") must be tagged like any other.

## Code rules

- Examples are real Laravel application code: real class names, real signatures, real
  namespaces. No `// do stuff`, no `...`, no pseudocode.
- PHP code blocks declare `php`; snippets embedded in prose may be bare.
- Each example shows its imports once (or uses FQCNs) so it can be pasted.
- Framework defaults are not repeated from memory — if a default matters ("null is
  cached for 60s"), the value must be stated **per version** or marked `<!-- VERIFY -->`.
- No deprecated APIs; if an API differs across versions, the example targets the
  **lowest supported Laravel version** and the difference is documented.

## Version table format

```markdown
| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `Cache::flexible()` | ❌ not available | ✅ since 11.9 | ✅ | ✅ |
```

- Only rows whose behavior **actually differs** (or whose introduction version matters)
  belong here. Do not pad the table.
- Symbols: ✅ available · ❌ not available · ⚠️ available with caveat (explained below
  the table).
- Every non-obvious cell traces to the official docs or framework source. If you cannot
  verify it, either omit the row or mark it `<!-- VERIFY: ... -->`.

## Voice

- Second person, present tense, declarative. "The worker holds the transaction open
  while it calls the HTTP API."
- No hedging ("might possibly"), no intensifiers ("very"), no marketing ("blazing").
- Opinions are allowed and welcome when marked: *"In our experience X fails before Y;
  start with Z."*
