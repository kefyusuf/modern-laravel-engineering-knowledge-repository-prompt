# Charter

## Mission

A curated engineering handbook for intermediate → senior Laravel engineers building
production systems. Every chapter answers the questions that actually decide whether a
technique survives production:

1. What problem does this solve?
2. When should it be used?
3. When should it **not** be used?
4. What is the simplest correct implementation?
5. What does a production implementation require?
6. What usually goes wrong?
7. How is it tested?
8. How is it operated?
9. How does it scale?
10. Which Laravel/PHP versions affect the approach?

## Non-goals

- **Not a syntax tutorial.** We assume you know what an Eloquent model is. We cover what
  happens when its lazy loading collides with a long-running worker.
- **Not a news feed.** We do not cover features until they have been verified against
  official documentation and, where relevant, executed in the test harness.
- **Not a package directory.** "Use library X" is only an answer when the pattern behind
  X is explained first.
- **Not exhaustive.** A missing chapter is better than a shallow one. The
  [roadmap](../README.md#roadmap) lists what is wanted; the
  [content standard](content-standard.md) defines what "done" means.

## The trust model

Everything in this repository is engineered so a reader can decide *how much to trust a
given claim*:

| Trust level | Meaning | How you can verify it |
|---|---|---|
| ⚙️ **Executed** | The claim is encoded as a test in [`examples/`](../examples/README.md) and runs in CI against the Laravel version matrix. | Read the test; read the CI run. |
| 📖 **Documented** | Verified against official Laravel documentation or framework source for a stated version. | The chapter's *Version differences* section cites the version. |
| ⚠️ **Judgment** | Engineering opinion, clearly marked as such. | The reasoning is given; disagree in an issue. |

Anything that could not be verified at review time carries an explicit
`<!-- VERIFY: description -->` marker, and CI fails if any marker reaches `main`
(see [version policy](version-policy.md)).

## Audience and tone

- You have shipped Laravel applications and been paged for them.
- Prose is direct and technical. No filler, no marketing.
- Every substantial concept follows the
  [example quality ladder](content-standard.md#example-quality-ladder):
  bad implementation → why it fails → minimal implementation → production-safe
  implementation → tests → operational considerations.
- Examples are runnable application code, not pseudocode. Load-bearing examples are
  executed in CI (⚙️) — see [examples strategy](examples.md).

## Ownership

| Question | Answer |
|---|---|
| Content licensing | [CC BY 4.0](../LICENSE.md) |
| Code and example licensing | MIT ([LICENSE-CODE](../LICENSE-CODE)) |
| How to contribute | [CONTRIBUTING.md](../CONTRIBUTING.md) |
| How Laravel versions are handled | [version-policy.md](version-policy.md) |
| Architectural decisions | [decisions/](decisions/) |
