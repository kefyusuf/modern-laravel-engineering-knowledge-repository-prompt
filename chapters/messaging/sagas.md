---
title: Sagas and process managers
topic: sagas
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/SagasExamplesTest.php
---

# Sagas and process managers

A saga is a **multi-step flow with compensation**: when step 3 fails, the work
of steps 1 and 2 is *undone* through explicit reverse actions — because there
is no distributed transaction to roll everything back. The "process manager"
is the code that owns the flow's state: which steps ran, what to do on failure,
what to do on timeout. This chapter is the hand-built, Laravel-native version —
no first-class framework ships in core (through Laravel 13), and the patterns are small
enough to own.

## TL;DR

- **Use it when:** a business flow spans multiple *independent* systems or
  aggregates, each step is individually reliable (queues + retries), but the
  whole has no atomicity — and partial completion must be reversible.
- **Don't use it when:** one transaction covers the whole flow (write the use
  case — [Transactions](../data/transactions.md)), or the flow is two steps where "try
  harder on step 2" beats compensation.
- **The classic production incident:** money captured, order creation failed —
  and the capture stays captured because nobody wrote the refund path. Second
  classic: the compensation action itself runs twice (retries are real) and
  the refund double-fires.

## Problem

Steps that span system boundaries cannot share a transaction
([Transactions](../data/transactions.md): cross-service transactions do not exist).
The naive fix — "call everything in order, hope" — leaves partial states:

- Payment captured, order row insert failed → money with no order.
- Inventory reserved, shipment request failed → stock locked forever.
- Email sent, webhook delivery failed → the receiver missed the fact, the
  sender doesn't know.

A saga makes the partial state **explicit and managed**: the flow's state
lives in a table, each step is idempotent ([Idempotency](idempotency.md)),
and failure triggers **compensation** — reverse actions executed in reverse
order, themselves idempotent, themselves retried.

## When to use it

- Flows crossing service/aggregate boundaries with a real reversal story
  (charge → refund; reserve → release; provision → deprovision).
- Long-running processes whose steps are already queues (hours/days between
  steps — state must live somewhere durable).
- Workflows where *timeout* is a failure mode ("supplier confirmation didn't
  arrive within 48h → cancel reservation").

## When NOT to use it

- **One transaction suffices.** Everything inside one database? Use
  `DB::transaction` — a saga around a local transaction is ceremony with new
  failure modes.
- **Compensation doesn't exist.** If a step's effect cannot be reversed
  (a sent email can't be unsent; a submitted legal filing can't be withdrawn),
  a saga can't help — design the step to be *last*, or make it manually
  recoverable.
- **The flow is sequential-and-fast with one outcome.** That's a use case
  ([boundaries](../architecture/boundaries.md)), not a saga.
- **You need strict atomicity for the whole flow.** No pattern gives you that
  across systems; if the business truly requires it, the systems belong
  together.

## Orchestration vs choreography — the saga tiebreak

[EDA](../architecture/eda.md) frames the choice; sagas are the tiebreak:

- **Choreographed sagas** (each service reacts to facts, no coordinator) fit
  ≤3 steps with no shared decisions. The flow's state is *implicit* — spread
  across everyone's databases.
- **Orchestrated sagas** (one process manager owns the state machine) fit
  anything with ordering, timeouts, or branch decisions. The state is
  *explicit* — inspectable, recoverable, testable.

The default recommendation: **orchestrate**. A debuggable flow table beats an
elegant event graph you can't answer "where is order 42's flow?" about.

## The simplest correct implementation

The state tables plus an orchestrator with explicit compensation:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saga_instances', function (Blueprint $table) {
            $table->id();
            $table->string('flow');              // 'order_fulfillment'
            $table->unsignedBigInteger('aggregate_id');
            $table->string('state');             // running | completed | compensated | failed
            $table->json('completed_steps');     // executed STEP NAMES, in order — the recovery input
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            $table->index(['flow', 'aggregate_id']);
        });

        // The stand-in for each step's REMOTE effect — what the compensation
        // reverses. A real saga's table here is per-step-system.
        Schema::create('saga_step_effects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saga_id');
            $table->string('step');
            $table->timestamp('created_at');
            $table->unique(['saga_id', 'step']);
        });

        // The compensation ledger: its unique index IS the idempotency of
        // the undo (Idempotency chapter, layer 3).
        Schema::create('saga_compensations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saga_id');
            $table->string('step');
            $table->timestamp('created_at');
            $table->unique(['saga_id', 'step']);
        });
    }
};
```

<!-- example: sagas/compensation-on-failure -->
```php
<?php

namespace App\Flows;

use Illuminate\Support\Facades\DB;

/**
 * The process manager: owns the flow's state, runs steps in order, and
 * compensates in reverse on failure. Each step is a NAMED callable pair:
 * the action and its compensation — both idempotent (Idempotency chapter);
 * the name is the recovery ledger's key.
 */
final class SagaRunner
{
    /**
     * @param  array<int, array{name: string, run: callable(int $sagaId): void, undo: callable(int $sagaId): void}>  $steps
     */
    public function run(string $flow, int $aggregateId, array $steps): void
    {
        $sagaId = DB::table('saga_instances')->insertGetId([
            'flow' => $flow,
            'aggregate_id' => $aggregateId,
            'state' => 'running',
            'completed_steps' => json_encode([]),
            'created_at' => now(),
        ]);

        $executed = [];

        try {
            foreach ($steps as $step) {
                $step['run']($sagaId);
                $executed[] = $step;

                // The state update commits SEPARATELY from the step's own
                // work: the saga must know what ran even if the step's
                // system is down at query time. The ledger records the
                // STEP NAMES — the recovery input, not array indexes.
                DB::table('saga_instances')
                    ->where('id', $sagaId)
                    ->update([
                        'completed_steps' => json_encode(array_column($executed, 'name')),
                        'updated_at' => now(),
                    ]);
            }

            DB::table('saga_instances')->where('id', $sagaId)
                ->update(['state' => 'completed', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            foreach (array_reverse($executed) as $step) {
                $step['undo']($sagaId); // idempotent, retried by ITS queue on failure
            }

            DB::table('saga_instances')->where('id', $sagaId)
                ->update(['state' => 'compensated', 'updated_at' => now()]);

            throw $e; // the caller decides: alert, retry the saga, or dead-end
        }
    }
}
```

That is the whole core: **state table, ordered steps, reverse compensation,
separate state commits.** Everything else in this chapter is what production
adds around it.

## What production requires

- **Idempotent steps AND idempotent compensations.** The runner retries
  (`attempts: 3` style — [Transactions](../data/transactions.md)); a
  compensation that runs twice must be a no-op — dedupe on the saga id
  ([Idempotency](idempotency.md) layer 3). The undo claims its compensation
  through the same unique-index pattern every other dedupe uses:

<!-- example: sagas/compensation-idempotent -->
```php
<?php

namespace App\Flows;

use Illuminate\Support\Facades\DB;

final class Compensations
{
    /**
     * The undo claims its compensation via the unique (saga_id, step)
     * ledger — and the claim + the reversal commit ATOMICALLY (the same-
     * transaction rule every layer-3 dedupe follows): a retried undo is a
     * no-op, and a crash mid-undo never leaves the ledger saying
     * "compensated" while the effect still stands.
     */
    public static function undoEffect(int $sagaId, string $step): void
    {
        DB::transaction(function () use ($sagaId, $step) {
            $claimed = DB::table('saga_compensations')->insertOrIgnore([
                'saga_id' => $sagaId,
                'step' => $step,
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return; // already compensated — the retried undo is a no-op
            }

            DB::table('saga_step_effects')
                ->where('saga_id', $sagaId)
                ->where('step', $step)
                ->delete();
        });
    }
}
```

- **State commits are separate from step effects** — the runner's own state
  update must never roll back with a step (it records *what happened*, not
  *what should have happened*).
- **Timeouts are steps' failure mode:** each step's system has an SLA; a
  scheduled command sweeps `running` sagas older than their deadline and
  treats silence as failure (compensate + alert).
- **The compensation order is reverse, and each undo knows its own saga id** —
  so the undo's queue retries reference the right instance.
- **Human escalation for non-compensable steps:** the saga table's `failed`
  state is the ops queue ([outbox](outbox.md) poison quarantine applies).

## What usually goes wrong

1. **The non-idempotent compensation.** *Root cause:* "refund the charge" as
   a plain call — retried once, refunded twice. *Detection:* double refunds
   correlated with compensation retries. *Fix:* dedupe on saga id + step
   name ([Idempotency](idempotency.md)); the harness executes this claim.
2. **State recorded inside the step's transaction.** *Root cause:* marking
   "step done" in the same commit as the step's remote call — the call can
   succeed while the commit rolls back, so the saga re-runs a completed step.
   *Detection:* steps executing twice with the step system seeing duplicates.
   *Fix:* the step itself is idempotent AND the state update stands alone
   (the reference implementation's separate update).
3. **The forgotten timeout.** *Root cause:* a step's dependency accepts the
   request and never responds — the saga waits forever in `running`.
   *Detection:* saga instances older than the flow deadline. *Fix:* the
   sweeper command + alerting ([Queues](../messaging/queues.md) monitor
   posture).
4. **Compensation ordering broken.** *Root cause:* undoing steps in the
   wrong order (refunding before releasing the reservation that references
   the payment). *Detection:* compensation failures cascading. *Fix:* reverse
   order is the rule; document per-step dependencies.
5. **Compensation failures swallowed.** *Root cause:* the catch block that
   compensates also catches the compensation's own exception and moves on —
   money left captured, saga marked compensated. *Detection:* sagas marked
   `compensated` with live side effects. *Fix:* compensation failures put the
   saga in `failed` + alert; never silently continue.
6. **The saga as a hidden call chain.** *Root cause:* steps that are really
   one local use case split into "saga steps" for the architecture's sake.
   *Detection:* every step shares one database and one failure domain.
   *Fix:* collapse to a use case ([boundaries](../architecture/boundaries.md)).

## How to test it

- **The compensation flow (⚙️ `examples/tests/SagasExamplesTest.php`):** two
  steps, the second throws — assert the first's undo ran, the state is
  `compensated`, and the exception propagated.
- **Compensation idempotency:** run the undo twice (the retry case) — assert
  the effect applied once (the dedupe claim, executed).
- **The state ledger:** each completed step is recorded before the next runs;
  a failure mid-flow leaves the exact executed-prefix in `completed_steps` —
  the recovery input.
- **Timeout sweeping:** a scheduled-command test with `Carbon::setTestNow`
  ([testing](../quality/testing.md)) asserting stale `running` sagas get
  compensated.

## How to operate it

- **The saga dashboard:** instances by state per flow (`running` count and
  oldest age = the flow's health), compensation rate, timeout rate.
- **The failed-state queue:** `state = 'failed'` rows need humans — that's
  the designed escalation, not a bug ([Queues](../messaging/queues.md)
  failed-job discipline).
- **3am runbook:** "money captured but no order" → find the saga instance by
  aggregate → state says what ran → run the documented compensation or
  re-drive the flow; timeouts → check the step's dependency first, sweep
  second.

## How it scales

- **Saga instances are rows** — the state table scales like any table
  ([database performance](../data/database-performance.md)); prune completed
  instances on retention.
- **Parallel steps** (fan-out/fan-in) extend the model: record each branch's
  completion, proceed when all branches report — the process manager's data
  model absorbs it; the correctness rules don't change.
- **The ceiling:** flows with hundreds of steps or heavy branching are a
  workflow-engine conversation — but most systems never outgrow "state table
  with ordered steps and compensation".

### When to reach for an engine

The hand-built runner covers most flows; three signals say "evaluate an
engine anyway":

1. **Flows need durable, resumable execution across restarts** — the runner
   re-drives from the state table, but hundreds of long-running flows with
   weeks-long steps (approval chains, provisioning waits) want an engine
   whose execution state, timers, and retries are its core competency
   (Temporal-class durable-execution systems have mature PHP SDKs).
2. **The flow graph is dynamic** — branches decided by data, steps added by
   configuration rather than code. A state machine per model
   (e.g. `spatie/laravel-model-states` for model lifecycle) covers the
   common middle ground without a workflow engine.
3. **Multiple teams own steps of shared flows** — an engine gives the
   step-ownership and versioning model; the runner assumes one owner.

The evaluation bar: the engine must reduce the state-management code you
own, not relocate it. A workflow engine is a distributed system you adopt —
the same weight class as [external brokers](external-brokers.md).

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| First-class saga engine in core | ❌ (hand-built) | ❌ | ❌ | ❌ |
| Job batching (`Bus::batch`) — callbacks for the choreographed variant | ✅ | ✅ | ✅ | ✅ |
| Everything the reference implementation uses (transaction, upsert, queues) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

No saga framework exists in core through Laravel 13 — the ~100 lines above
are the pattern, and owning them is cheaper than adapting a generic workflow
engine for most flows.

## Related

- [Idempotency](idempotency.md) — the contract that makes steps and
  compensations retry-safe.
- [Transactions](../data/transactions.md) — why the boundary can't span the
  flow; the local-transaction alternative.
- [The transactional outbox](outbox.md) — how saga steps publish facts
  reliably.
- [Queues](../messaging/queues.md) — the retry/failed machinery each step
  rides on.
- [EDA](../architecture/eda.md) — the orchestration-vs-choreography decision.
- Roadmap: workflow-engine comparison, temporal-pattern deep dive.
