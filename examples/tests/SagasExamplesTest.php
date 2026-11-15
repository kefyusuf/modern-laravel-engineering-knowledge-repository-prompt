<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\SagaRunner;

uses()->group('sagas');

/**
 * Executed claims for chapters/messaging/sagas.md.
 */
it('compensates executed steps in reverse when a later step fails', function () {
    /** @example-id sagas/compensation-on-failure */

    // The steps' "remote effects" are rows in saga_step_effects; the second
    // step's dependency fails (throws), as injected at its seam.
    $steps = [
        [
            'name' => 'reserve_inventory',
            'run' => function () {
                DB::table('saga_step_effects')->insert([
                    'saga_id' => 1, 'step' => 'reserve_inventory', 'created_at' => now(),
                ]);
            },
            'undo' => fn () => SagaRunner::undoEffect(1, 'reserve_inventory'),
        ],
        [
            'name' => 'capture_payment',
            'run' => function () {
                throw new RuntimeException('payment gateway down');
            },
            'undo' => fn () => SagaRunner::undoEffect(1, 'capture_payment'),
        ],
    ];

    $runner = new SagaRunner;

    try {
        $runner->run('order_fulfillment', 42, $steps);
        $this->fail('expected the step failure to propagate');
    } catch (RuntimeException) {
    }

    // The executed step's effect was UNDONE (row gone)...
    expect(DB::table('saga_step_effects')->count())->toBe(0);
    // ...the compensation is recorded...
    expect(DB::table('saga_compensations')->where('step', 'reserve_inventory')->count())->toBe(1);
    // ...the saga state says what happened...
    $saga = DB::table('saga_instances')->first();
    expect($saga->state)->toBe('compensated');
    // ...and the failure propagated to the caller (alert/retry decision).
    expect(DB::table('saga_instances')->value('completed_steps'))->toBe('["reserve_inventory"]');
});

it('absorbs retried compensations through the dedupe ledger', function () {
    /** @example-id sagas/compensation-idempotent */

    // A step's effect exists (the flow ran step one)...
    DB::table('saga_step_effects')->insert([
        'saga_id' => 7, 'step' => 'reserve_inventory', 'created_at' => now(),
    ]);

    // ...the compensation runs — and then RUNS AGAIN (its own queue retried
    // it, or the saga re-drove the flow). The ledger makes the retry a no-op.
    SagaRunner::undoEffect(7, 'reserve_inventory');
    SagaRunner::undoEffect(7, 'reserve_inventory');

    expect(DB::table('saga_step_effects')->count())->toBe(0);
    expect(DB::table('saga_compensations')->count())->toBe(1);
});
