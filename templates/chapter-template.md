---
title: "{{Title}}"
topic: "{{kebab-slug}}"
status: draft
reviewed-against: "{{Laravel X.Y and Z docs, YYYY-MM}}"
testbed: examples/tests/{{Topic}}ExamplesTest.php
---

<!--
Copy this file to chapters/<domain>/<topic>.md and delete this comment.
Section headings below are part of the contract — see docs/content-standard.md
before writing. Remove the testbed line if the chapter has no executed examples.
-->

# {{Title}}

{{2–4 sentence framing: what this is, and the one-line "shape" of the decision.}}

## TL;DR

- **Use it when:** …
- **Don't use it when:** …
- **The classic production incident:** …

## Problem

{{The concrete production failure mode.}}

## When to use it

{{Concrete signals.}}

## When NOT to use it

{{At least 3 situations, each with what to do instead.}}

## The simplest correct implementation

{{Smallest code that is still correct. Tag only if this block has a harness test.}}

## What production requires

<!-- example: {{topic}}/{{slug}} -->

```php
{{production-safe implementation}}
```

{{Non-code requirements: config, infrastructure, monitoring, failure drills.}}

## What usually goes wrong

{{For each: root cause → detection signal → fix.}}

1. …

## How to test it

{{The test that would catch the bug. Link the ⚙️ harness tests.}}

## How to operate it

{{Dashboards, commands, runbooks, 3am checks.}}

## How it scales

{{The ceiling; what breaks first; the next lever.}}

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| {{behavior}} | ✅ | ✅ | ✅ | ✅ |

## Related

- `../<path>.md` — [Chapter title] — why you'd care.
- Roadmap: {{topic}} (not yet written) — why you'll want it.
