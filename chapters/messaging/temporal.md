---
title: Durable execution with Temporal
topic: temporal
status: draft
reviewed-against: "Temporal PHP SDK & docs, 2026-11"
---

# Durable execution with Temporal

Durable execution is the step beyond the hand-built [saga runner](sagas.md):
an **engine owns the flow's state, timers, retries, and visibility**, so your
code contains only the steps. Temporal is the leading open-source engine with
a mature PHP SDK — and the honest question this chapter answers is when its
weight buys you something the ~100-line runner doesn't. The mental-model shift
is the real cost: **workflows are deterministic replay, activities are side
effects** — get that backwards and the engine becomes the incident.

## TL;DR

- **Use it when:** flows are long-running (hours/days/weeks), state machines
  are dynamic (branches, timers, human approval), step-ownership is
  multi-team, or you need *visibility into every flow instance* as a product
  requirement — and you accept a second runtime (RoadRunner workers + the
  Temporal server) as infrastructure.
- **Don't use it when:** the hand-built [saga runner](sagas.md) covers the
  flows, or the "workflow" is really a job chain — Temporal is a distributed
  system you adopt, the heaviest item in this handbook's toolbox.
- **The classic production incident:** the workflow code deployed while old
  executions were mid-flight, and replay diverged — Temporal requires
  workflow-code versioning discipline (pin old executions to the code that
  started them), and skipping that is how an innocent-looking change breaks
  every running workflow.

## Problem

The hand-built saga runner solves state ownership and compensation. What it
does *not* solve, and what grows painful at scale:

- **Timers and waits** — "wait 48h for supplier confirmation" means a
  scheduled sweeper + state encoding
  ([sagas](sagas.md)' forgotten-timeout failure). Engines make timers a
  primitive: durable, resumable, cluster-restart-safe.
- **Visibility per instance** — "where is flow 42 exactly?" is a dashboard
  query in an engine; a grep over a state table in the hand-built version.
- **Dynamic graphs** — branches decided by data, signals from humans or
  other systems mid-flow. Encoding those into a `completed_steps` JSON
  column is where hand-built runners become accidental interpreters.
- **Execution continuity** — an engine survives restarts, deployments, and
  worker crashes *mid-flow* without you re-deriving where to resume.

The price: a second runtime (the Temporal server + RoadRunner worker
processes), a deterministic-replay programming model, and versioning
discipline for workflow code.

## When to use it

- **Long-running, multi-system flows** with waits, human steps, or SLA
  timers measured in hours/days — approvals, provisioning, claim lifecycles.
- **Flows where instance visibility is a product need** (support sees where
  order 42 is, in real time).
- **High fan-out orchestration** across services, where the hand-built
  runner's single-table ledger would become the bottleneck.

## When NOT to use it

- **The runner covers it.** If your flows fit "ordered steps, compensation,
  and a deadline sweep", the [sagas](sagas.md) pattern is less infrastructure
  with the same guarantees.
- **Job chains.** A → B → C with no waits or branches is
  [queued jobs](queues.md) with retries — an engine adds a cluster to
  rename your queue.
- **High-frequency, short-lived "flows".** Temporal's sweet spot is
  long-lived durability; a 200ms flow pays engine overhead for nothing
  ([queues](queues.md) + [idempotency](idempotency.md) is the tool).
- **As your database.** Workflow state is *execution state*, not business
  data — the business truth stays in your tables
  ([Transactions](../data/transactions.md)).

## The mental model (get this right first)

Two kinds of code live in a Temporal worker, and confusing them is the
classic failure:

- **Workflow code** — the orchestrator. It must be **deterministic**: same
  inputs → same decisions, because the engine *replays* it on every
  restart/worker handoff to reconstruct state. No I/O, no `now()`, no
  randomness, no direct DB access. Side effects happen via activities.
- **Activity code** — the side effects: HTTP calls, DB writes, mail. They
  run on workers with normal Laravel access, and must be **idempotent**
  ([Idempotency](idempotency.md)) because at-least-once delivery survives
  here too.

The replay rule also governs **changes**: deploying new workflow code while
old executions run means two versions replaying — Temporal pins running
executions to the code version that started them (via the framework's
versioning/replay mechanisms), and skipping that discipline is the
production incident this chapter opens with.

## The Laravel integration shape

Verified components: the SDK is **`temporalio/sdk-php`**, workers run under
**RoadRunner** (its Temporal plugin spawns and supervises the PHP worker
processes via `.rr.yaml` — RoadRunner is a dependency of the SDK's runtime,
not an optional detail), and the community integration
**`keepsuit/laravel-temporal`** adds artisan generators plus a worker-start
command with Laravel container access.

```text
1. composer require temporalio/sdk-php keepsuit/laravel-temporal
2. php artisan temporal:work      # RoadRunner-backed worker (keepsuit)
3. The Temporal server itself: a separate cluster (self-hosted or Temporal Cloud)
```

The worker is a **long-lived process** — supervised like the queue workers
([deployment](../runtime/deployment.md): autorestart, `stopwaitsecs`, reload
on deploy) and subject to the same state discipline questions as
[Octane](../runtime/octane.md) workers: what persists across activities on one worker?

## The simplest correct implementation

The money-transfer saga from [sagas](sagas.md), as a Temporal workflow with
compensation — note what *disappears* from your code:

```php
<?php

namespace App\Flows\Transfer;

use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class TransferWorkflow
{
    private array $compensated = [];

    #[WorkflowMethod(name: 'transfer.execute')]
    public function execute(int $fromAccountId, int $toAccountId, int $amountCents): iterable
    {
        // Deterministic orchestration: every decision is replayable.
        try {
            yield Workflow::newActivityStub(
                DebitActivity::class,
                startToCloseTimeout: '30 seconds',
                retry: ['maximumAttempts' => 5],
            )->debit($fromAccountId, $amountCents);

            yield Workflow::newActivityStub(
                CreditActivity::class,
                startToCloseTimeout: '30 seconds',
                retry: ['maximumAttempts' => 5],
            )->credit($toAccountId, $amountCents);

            // Durable timer: survives worker restarts and server outages —
            // no sweeper command to build (the sagas chapter's "forgotten
            // timeout" failure mode is the engine's job now).
            yield Workflow::timer(48 * 3600); // confirmation window

            return 'completed';
        } catch (\Throwable $e) {
            yield $this->compensate();
            return 'compensated';
        }
    }

    private function compensate(): iterable
    {
        // Compensation activities run in reverse order; the engine tracks
        // which side effects actually happened and their own retries.
        yield Workflow::newActivityStub(CompensationActivity::class)
            ->reverseLastTransfer();
    }
}
```

What vanished relative to the hand-built runner: the state table, the
sweeper command, the retry loops, the
[timeout](../data/locking.md) bookkeeping — the engine owns all four. What
remains yours: deterministic workflow logic, idempotent activities
([idempotency](idempotency.md)), and versioning discipline.

## What production requires

- **The server is infrastructure with an owner** — self-hosted cluster or
  Temporal Cloud; sizing, upgrades, and retention policies are standing
  work ([deployment](../runtime/deployment.md) gravity).
- **Workflow versioning discipline:** changes to running workflow code must
  be replay-safe — the engine's versioning mechanisms (pinned histories,
  `Workflow::versioning` APIs) are part of every deploy runbook; a
  non-replay-safe change breaks *every in-flight execution*, silently.
- **Worker pool sizing and supervision:** RoadRunner workers are
  long-lived — supervisor posture, memory ceilings, and reload-on-deploy
  mirror the [queues](../messaging/queues.md) rules.
- **Laravel access inside activities:** activities run in workers with the
  Laravel container (via the integration package) — DB connections,
  [queues](../messaging/queues.md), and
  [Context](../interfaces/../data/caching.md)-style per-execution state
  follow the same long-lived-process hygiene as Octane workers.
- **Observability:** Temporal ships its own visibility (per-instance state,
  history queries) and integrates with
  [tracing](../quality/tracing.md) on workers — the saga dashboard's successor.

## What usually goes wrong

1. **Non-deterministic workflow changes.** *Root cause:* editing a running
   workflow's decision logic without versioning — replay diverges, the
   execution errors. *Detection:* in-flight workflows erroring right after
   a deploy. *Fix:* version or restructure (new workflow type for new
   logic; old executions finish on old code).
2. **Side effects in workflow code.** *Root cause:* "just one HTTP call"
   inside the workflow body — replay executes it again. *Detection:*
   duplicated external effects after worker restarts. *Fix:* side effects
   belong in activities, always.
3. **The worker fleet forgotten at deploy.** *Root cause:* code changes
   shipped, workers not restarted (or restarted without draining).
   *Detection:* "the fix isn't deployed" for activity behavior. *Fix:*
   the release flow covers Temporal workers exactly like
   [queue workers](../messaging/queues.md).
4. **Treating the engine as the business database.** *Root cause:* payload
   and state accumulations living in workflow history. *Detection:*
   history sizes bloating; slow replay. *Fix:* ids in, ids out; business
   state in your tables ([Transactions](../data/transactions.md)).
5. **Compensation logic outside the engine.** *Root cause:* "we already
   have a refund endpoint" — the workflow marks compensated while the real
   reversal runs on a different path. *Detection:* the sagas chapter's
   swallowed-compensation failure, now with two owners. *Fix:* one
   compensation owner; the engine's compensation activities are it.

## How to test it

- **Workflow replay tests:** the SDK supports testing workflows without a
  server — assert the activity sequence, the timer, and the compensation
  path as a deterministic tree. This is the framework-free discipline of
  [testing](../quality/testing.md) applied to the engine's own model.
- **Activity idempotency:** the same
  [idempotency](idempotency.md) matrix — redelivery, retry, replay.
- **Version-compat drill:** start a workflow on the current code, deploy
  changed workflow code, assert the running instance completes — the
  production incident of *usually goes wrong* #1, rehearsed.
- The framework-free claims ([sagas](sagas.md)) are the baseline the
  engine must beat, not replace.

## How to operate it

- **Instance visibility is the dashboard:** running/failed/completed per
  workflow type, oldest running instance, timer volume — the saga
  dashboard, now native.
- **Worker metrics:** worker count, activity latency, task-slot
  utilization — the [queues](../messaging/queues.md) discipline on a new
  transport.
- **Server ops (self-hosted):** persistence health, cluster upgrades,
  namespace retention — the reason Temporal Cloud exists; either way, give
  the server an owner.
- **3am runbook:** in-flight workflow errors after deploy → replay
  divergence (versioning miss); activities stuck → worker fleet or
  dependency; compensation missing → is the compensation *activity* the
  owner, or did a parallel path claim it?

## How it scales

- **Workers scale horizontally** (RoadRunner spawns per `.rr.yaml`); the
  server scales separately; workflow throughput is bounded by deterministic
  replay cost — keep workflows thin.
- **The engine removes the scaling ceilings the runner hits** (timer
  sweeps, ledger growth, claim contention) and replaces them with
  cluster scaling — a better trade at volume.
- **The ceiling:** engine adoption is a platform commitment — namespaces,
  retention, SDK upgrades, worker fleets. Adopt when the flow portfolio
  (not one flow) justifies it; one complex flow is what
  [sagas](sagas.md) is for.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| First-class Temporal support in core | ❌ | ❌ | ❌ | ❌ |
| `temporalio/sdk-php` + RoadRunner worker path | ✅ | ✅ | ✅ | ✅ |
| `keepsuit/laravel-temporal` integration | ✅ (verify the package's Laravel constraint per release) | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Temporal is framework-agnostic infrastructure — the SDK and RoadRunner
evolve on their own release trains (verify the SDK's Laravel constraint per
adopted version, the same discipline as
[external brokers](external-brokers.md)' package pins).

## Related

- [Sagas](sagas.md) — the hand-built runner this engine supersedes at
  scale; the decision section lives there.
- [Queues](../messaging/queues.md) — jobs vs durable execution; the
  boundary between them.
- [Transactions](../data/transactions.md) — the consistency boundary
  activities must respect per aggregate.
- [Idempotency](idempotency.md) — activities are at-least-once.
- [EDA](../architecture/eda.md) — workflows consume and emit facts; the
  ladder's delivery guarantees still apply.
- [Octane](../runtime/octane.md) — the long-lived-worker hygiene that RoadRunner
  workers share.
- Roadmap: Temporal versioning patterns deep dive, Signal/Query patterns.
