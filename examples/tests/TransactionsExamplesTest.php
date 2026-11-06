<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Jobs\Shim;
use MLE\Examples\Tests\Fixtures\Transactions;

uses()->group('transactions');

/**
 * Executed claims for chapters/data/transactions.md. Each test docblock maps
 * to a tagged example block via the harness mapping contract.
 */
it('dispatches queued side effects only after the commit', function () {
    /** @example-id transactions/after-commit */
    config(['queue.default' => 'database']);

    DB::table('inventories')->insert(['id' => 1, 'available' => 5]);

    DB::transaction(function () {
        DB::table('inventories')->where('id', 1)->update(['available' => 4]);

        // Dispatched INSIDE the transaction, but afterCommit defers the
        // actual enqueue until COMMIT: no job row may exist here.
        Shim::dispatch(99)->afterCommit();

        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->count())->toBe(1);
});

it('keeps external side effects outside the transaction boundary', function () {
    /** @example-id transactions/short-transactions */
    config(['queue.default' => 'database']);
    DB::table('inventories')->insert(['id' => 1, 'available' => 5]);

    $fixture = new Transactions;
    $orderId = $fixture->placeOrder(1, 2, 10);

    // State committed...
    $order = DB::table('orders')->where('id', $orderId)->first();
    expect($order->status)->toBe('pending');
    expect(DB::table('inventories')->find(1)->available)->toBe(3);

    // ...boundary closed, and the "external" work enqueued after it.
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
});

it('enforces the invariant with an atomic conditional update', function () {
    /** @example-id transactions/lost-update */
    DB::table('inventories')->insert(['id' => 1, 'available' => 1]);

    $fixture = new Transactions;

    // Worker A reads (pre-atomic-era check), worker B wins the decrement.
    $aSees = DB::table('inventories')->find(1)->available;
    expect($aSees)->toBe(1);
    expect($fixture->atomicDecrement(1, 1))->toBeTrue();

    // Worker A's stale check must NOT be able to force the write: the
    // WHERE guard turns "lost update" into a clean no-op.
    expect($fixture->atomicDecrement(1, 1))->toBeFalse();
    expect(DB::table('inventories')->find(1)->available)->toBe(0);
});

it('retries only deadlock-class failures and rethrows business exceptions', function () {
    /** @example-id transactions/deadlock-retry */
    $calls = 0;

    // A business exception is never retried.
    try {
        DB::transaction(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('insufficient stock');
        }, attempts: 3);
        $this->fail('expected exception');
    } catch (RuntimeException) {
        expect($calls)->toBe(1);
    }

    // A deadlock-class failure re-runs the closure. The detector accepts a
    // raw PDOException with SQLSTATE 40001 (verified against
    // ConcurrencyErrorDetector) — no QueryException wrapper needed, which
    // keeps this test independent of the QueryException constructor
    // signature that changed in Laravel 11.
    $deadlockCalls = 0;
    $result = DB::transaction(function () use (&$deadlockCalls) {
        $deadlockCalls++;
        if ($deadlockCalls === 1) {
            throw new PDOException('SQLSTATE[40001]: Deadlock found when trying to get lock', 40001);
        }

        return 'ok';
    }, attempts: 3);

    expect($deadlockCalls)->toBe(2);
    expect($result)->toBe('ok');
});
