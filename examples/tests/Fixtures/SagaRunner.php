<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * sagas/compensation-on-failure + sagas/compensation-idempotent — the
 * harness-local process manager (mirrors the chapter's reference shape):
 * ordered named steps, separate state commits, reverse compensation whose
 * undos are idempotent via the saga_compensations ledger.
 */
final class SagaRunner
{
    /**
     * @param  array<int, array{name: string, run: callable(): void, undo: callable(): void}>  $steps
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
                $step['run']();
                $executed[] = $step;

                // State commits SEPARATELY from each step's own effect —
                // the ledger records what ran even mid-flow.
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
                $step['undo']($sagaId);
            }

            DB::table('saga_instances')->where('id', $sagaId)
                ->update(['state' => 'compensated', 'updated_at' => now()]);

            throw $e;
        }
    }

    /**
     * sagas/compensation-idempotent: an undo claims its compensation via the
     * unique (saga_id, step) ledger — a retried undo is a no-op.
     */
    public static function undoEffect(int $sagaId, string $step): void
    {
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
    }
}
