---
title: Preview environments
topic: preview-environments
status: reviewed
reviewed-against: "Laravel 12.x docs, 2026-11"
---

# Preview environments

A preview environment is a full, disposable deployment of the application —
one per pull request, alive for the PR's lifetime. It converts "review the
diff" into "click the link and see the change", catches integration issues
real data surfaces (migrations on production-shaped schemas, third-party
callbacks, config drift), and replaces the "works on my machine" negotiation.
This chapter is the design: lifecycle, data strategy, and cost control — the
CI/CD chapter's ([ci-cd](ci-cd.md)) pipeline extended one step outward.

## TL;DR

- **Use it when:** more than one person reviews work, or any change touches
  migrations, frontend rendering, or third-party integrations — where a diff
  cannot show the truth.
- **Don't do it:** without lifecycle automation. A preview environment that
  needs manual teardown becomes a cost leak and a security surface ("the
  staging credentials in that June PR's environment").
- **The classic production incident:** preview environments sharing the
  production database "to test with real data" — a migration on the preview
  destroys production rows; or a preview's webhook URL receives a partner's
  real events and answers them.

## Problem

Code review catches what a diff shows. It misses what only *running* shows:

- **Migration behavior** on production-shaped data — the expand/contract
  sequencing ([deployment](deployment.md)) rehearsed per PR, automatically.
- **Frontend reality** — the rendered page, the CSS interaction, the flow a
  reviewer can click.
- **Integration truth** — third-party callbacks, queue behavior, the health
  gate ([ci-cd](ci-cd.md)) running against a full stack.

And it misses them for every PR, which is exactly where they're cheapest to
catch.

## When to use it

- Team size ≥ 2 reviewers, or any stakeholder (design, product) who reviews
  by looking.
- Changes touching [migrations](deployment.md), UI, or external
  integrations.
- CI capacity to host short-lived environments (they're PR-scoped — the
  cost scales with review activity).

## When NOT to use it

- **Docs-only or test-only changes.** The environment builds and idles —
  skip by path filters in the pipeline.
- **As a substitute for a staging environment.** Previews are per-PR and
  disposable; staging is shared, persistent, and production-shaped (its own
  discipline — [deployment](deployment.md)'s drill environment). Both have
  roles.
- **Without secret hygiene.** A preview gets *masked or synthetic* data and
  *scoped* third-party keys — production credentials in a preview is the
  breach with a URL.

## The simplest correct implementation

The lifecycle, keyed on the PR:

```text
PR opened / updated:
  1. build the artifact (CI's existing pipeline — ci-cd chapter)
  2. provision: app container + ephemeral database + scoped secrets
  3. migrate:     php artisan migrate --force (the REAL migration chain —
                  rehearsed per PR, on production-shaped data)
  4. seed:        masked/synthetic fixtures
  5. health gate: /up + dependency probes (deployment chapter)
  6. comment the URL on the PR

PR merged / closed:
  7. teardown:     containers, volumes, DNS, secrets — automated, verified
```

The two invariants that make previews trustworthy: **the migration chain is
real** (every PR rehearses [deployment](deployment.md)'s expand/contract
against production-shaped data — drift dies here), and **teardown is
automatic** (the environment outlives its PR by hours, not months).

## What production requires

### Data strategy — the decision that defines the preview

| Strategy | Fits | Know |
|---|---|---|
| **Synthetic fixtures** (seeders/factories) | Most previews; default choice | Deterministic seeds make reviews reproducible; visual reviewers see realistic shapes |
| **Masked snapshot** (production copy, PII-scrubbed) | Previews that must show realistic scale/content | Masking is a pipeline step with its own review — a bad mask IS a leak |
| **Production data** | Never | The incident above; webhooks, migrations, and access all point the wrong way |

### Isolation contract

- **Own database, own storage, own queues** per preview — a shared Redis or
  shared queue means one preview's [worker](../messaging/queues.md) consumes another's
  jobs; the [row-level security](../data/row-level-security.md) tenant
  discipline applied to environments.
- **Scoped third-party keys:** sandbox keys for payment/email providers; a
  webhook URL is *never* the production one (partners' events belong to
  production, a preview receiving them is answering real money).
- **Access control:** preview URLs are not public by default — auth-gated or
  unguessable + expiring; they run real code with real integrations.

### Cost and lifecycle control

- **Automatic teardown on merge/close** + a **sweeper** for abandoned PRs
  (a scheduled job kills previews older than N days — the retention
  discipline of [caching](../data/caching.md) applied to environments).
- **Resource ceilings per environment** (small containers, capped workers) —
  a preview exists for a human to click through, not for load testing.
- **One environment per PR** (rebuild on push, don't accumulate).

## What usually goes wrong

1. **Production data in previews.** *Root cause:* real data is convenient.
   *Detection:* a customer's name on a reviewer's screen; a migration
   "test" affecting production. *Fix:* the data-strategy table — synthetic
   default, masked snapshot with reviewed masking, never production.
2. **The forgotten environment.** *Root cause:* PR closed, teardown never
   ran. *Detection:* the cloud bill; an old preview URL resolving. *Fix:*
   the sweeper job + lifecycle alerts.
3. **Migration drift discovered late.** *Root cause:* previews skipped for
   migration-heavy PRs. *Detection:* staging/production migration surprises
   the preview would have caught. *Fix:* previews run the real chain — the
   [deployment](deployment.md) rehearsal is automatic here.
4. **Preview webhooks answering production.** *Root cause:* copied
   configuration. *Detection:* partners' events processed twice. *Fix:*
   scoped keys per environment; the config audit in the provisioning step.
5. **Shared infrastructure between previews.** *Root cause:* one Redis, one
   queue for "convenience". *Detection:* cross-PR job consumption; cache
   collisions. *Fix:* full isolation per environment (the contract above).
6. **Preview sprawl.** *Root cause:* every PR spawns everything (workers,
   schedulers, horizons) when a reviewer needs the web app. *Detection:*
   resource usage far above traffic. *Fix:* right-size the profile: web +
   database + queue for what the PR touches.

## How to test it

- **The provisioning pipeline is code** — tested like everything else: the
  lifecycle (create → migrate → health-gate → teardown) exercised on
  schedule, not just when a PR appears.
- **The migration rehearsal is the value:** previews running the real
  migration chain on production-shaped data is [deployment](deployment.md)'s
  rehearsal, executed continuously — the drift detection the
  [online DDL](../data/online-ddl.md) chapter needs.
- **Teardown verification:** a scheduled assertion that every environment
  maps to an open PR (orphans alert).
- Masking pipelines get their own tests: the masked snapshot's PII audit is
  a security property ([security](../interfaces/security.md)), asserted per run.

## How to operate it

- **The preview inventory:** one dashboard listing every environment, its
  PR, its age, its cost — the sweeper's input and the bill's explanation.
- **Access review:** who can reach preview URLs; the auth gate's expiry.
- **3am runbook:** previews don't page (that's the point) — the runbook
  entry is the sweeper's: orphaned environments and cost anomalies.

## How it scales

- **Cost scales with review activity** — the resource ceilings and the
  path-filtered provisioning keep it proportional.
- **The same provisioning code scales to ephemeral test environments**
  ([ci-cd](ci-cd.md)'s integration stage) — one pipeline, two consumers.
- **The ceiling:** organizational discipline — previews work when teardown
  is automatic and secrets are scoped; both are one-time decisions that
  decay without enforcement.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| App runs unchanged in ephemeral environments (config-driven) | ✅ | ✅ | ✅ | ✅ |
| `/up` health gate for provisioning verification | ❌ (custom) | ✅ | ✅ | ✅ |
| Migration chain execution (`migrate --force`) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Preview environments are infrastructure around an app that runs
version-agnostically — the framework's contribution is the health gate
(Laravel 11+) and config discipline.

## Related

- [CI/CD](ci-cd.md) — the pipeline the preview provisioning extends.
- [Deployment](deployment.md) — the release flow the previews rehearse per
  PR; staging's distinct role.
- [Online DDL](../data/online-ddl.md) — the rehearsal environment for big-table
  changes.
- [Security](../interfaces/security.md) — the secret-scope and data-masking
  disciplines.
- [Observability](../quality/observability.md) — the health checks the
  provisioning gate verifies.
- Roadmap: multi-preview topology sharing.
