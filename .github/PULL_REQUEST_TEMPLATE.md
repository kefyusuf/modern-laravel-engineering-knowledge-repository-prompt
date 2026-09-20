<!-- Title: "<topic>: <what changed>" -->

## What

<!-- One paragraph. What is being added or fixed, for whom. -->

## Contract compliance (chapters)

- [ ] Follows [`templates/chapter-template.md`](../templates/chapter-template.md) structure (fixed headings)
- [ ] Meets [`docs/content-standard.md`](../docs/content-standard.md) (voice, code rules, version table)
- [ ] Version claims cite primary sources, or carry explicit `<!-- VERIFY -->` markers
- [ ] `When NOT to use it` lists ≥3 situations with alternatives
- [ ] Production-safe examples tagged `<!-- example: topic/slug -->`
- [ ] Harness tests added/updated in `examples/tests/` with matching `@example-id` docblocks
- [ ] Frontmatter `reviewed-against` names specific versions + date
- [ ] `status:` set correctly (`draft` for new chapters)

## Verification

<!-- Paste the evidence: CI run link, command output, or the harness results. -->

## DCO

- [ ] Commits signed (`git commit -s`)
