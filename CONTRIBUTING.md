# Contributing

Thanks for wanting to improve this handbook. The rules here exist to protect the
[trust model](docs/charter.md#the-trust-model), not to gatekeep.

## Ways to contribute (cheapest first)

1. **Factual correction** — anything wrong or version-stale, with a primary source
   (official docs or framework source). No proposal needed; small PRs welcome.
2. **Failure-mode report** — a production incident a chapter's
   *What usually goes wrong* should cover. Open an issue with the narrative:
   root cause → detection signal → fix.
3. **New chapter** — proposal issue first (see below).
4. **Harness improvement** — better tests, new matrix cells, faster CI.

## Chapter lifecycle

```text
proposal issue → maintainer triage → draft PR (template + content standard)
   → automated gates (CI: lint, links, mapping, markers)
   → review: technical correctness + version accuracy (2 approvals)
   → status: reviewed
```

**Proposal issue** must state: the problem the chapter solves for a production
engineer, why it's not covered by existing chapters, the three failure modes it will
document, and the Laravel versions where behavior actually differs.

**Draft PR** must:

- start from [`templates/chapter-template.md`](templates/chapter-template.md);
- satisfy every rule in [`docs/content-standard.md`](docs/content-standard.md);
- carry ⚙️ tests in `examples/tests/` for every tagged production-safe example
  ([mapping contract](docs/examples.md));
- set `status: draft` — promotion to `reviewed` happens in the review round;
- sign commits (`git commit -s`, [DCO](https://developercertificate.org/)).

## Review checklist (what reviewers actually check)

- [ ] Ten contract questions answered; fixed section headings present.
- [ ] Every version claim cites a primary source or carries an explicit `VERIFY`
      marker (markers must be gone before `reviewed`).
- [ ] Bad → why → minimal → production ladder present where applicable.
- [ ] *When NOT to use it* is real, with alternatives.
- [ ] Examples are paste-able: real APIs, real signatures, imports shown.
- [ ] ⚙️ mapping intact in both directions; harness green in CI.
- [ ] No drift between chapter version table and harness skip lists.
- [ ] Cross-links resolve; roadmap topics referenced as text, not dead links.

## Style

- [Markdownlint config](.markdownlint-cli2.jsonc) is the formatting contract; run
  `npx markdownlint-cli2 "**/*.md"`.
- Line length: prose wraps at ~100 columns (code blocks exempt).
- Voice: see [content standard § Voice](docs/content-standard.md#voice).

## Local checks

```bash
npx markdownlint-cli2 "**/*.md"
node scripts/php-smoke.mjs            # PSR-4 + brace balance for examples/
node scripts/check-links.mjs
node scripts/check-example-mapping.mjs
node scripts/check-verify-markers.mjs   # passes on branches, fails on main
cd examples && composer install && vendor/bin/pest   # needs PHP 8.2+; Redis optional
```
