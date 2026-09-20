<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Queues\DeliverWebhook;
use MLE\Examples\Tests\Fixtures\Queues\RefreshProductAvailability;
use MLE\Examples\Tests\Fixtures\Queues\SendOrderInvoice;

/**
 * Executed claims for chapters/messaging/queues.md.
 */
it('guards the job body so a re-run is a no-op', function () {
    /** @example-id queues/basic-job */
    $orderId = DB::table('orders')->insertGetId([
        'customer_id' => 1,
        'sku_id' => 1,
        'quantity' => 1,
        'status' => 'completed',
        'placed_at' => now(),
    ]);

    $job = new SendOrderInvoice($orderId);
    $job->handle();
    $first = DB::table('orders')->find($orderId)->invoiced_at;
    expect($first)->not->toBeNull();

    // At-least-once delivery means handle() may run again: the guard
    // makes the second run a no-op.
    $job->handle();
    expect(DB::table('orders')->find($orderId)->invoiced_at)->toEqual($first);

    // Deleted order: clean early return, not a crash.
    DB::table('orders')->delete($orderId);
    (new SendOrderInvoice($orderId + 1))->handle();
    expect(true)->toBeTrue(); // no exception is the claim
});

it('declares and honors an explicit retry policy', function () {
    /** @example-id queues/retry-policy */
    $job = new DeliverWebhook(5, 'payload-1');

    expect($job->tries)->toBe(10);
    expect($job->maxExceptions)->toBe(3);
    expect($job->backoff())->toBe([10, 30, 90, 300]);
    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());

    // The failed() hook is the poison-message destination.
    DeliverWebhook::$failureNotifications = 0;
    $job->failed(new RuntimeException('downstream unavailable'));
    expect(DeliverWebhook::$failureNotifications)->toBe(1);
});

it('enqueues the job only after the commit lands', function () {
    /** @example-id queues/after-commit-dispatch */
    config(['queue.default' => 'database']);

    $orderId = DB::table('orders')->insertGetId([
        'customer_id' => 1,
        'sku_id' => 1,
        'quantity' => 1,
        'status' => 'pending',
        'placed_at' => now(),
    ]);

    (new MLE\Examples\Tests\Fixtures\Queues)->completeOrder($orderId);

    // The dispatch was written inside the transaction with afterCommit:
    // the job row exists only because the COMMIT succeeded first.
    $order = DB::table('orders')->find($orderId);
    expect($order->status)->toBe('completed');
    expect(DB::table('jobs')->where('queue', 'default')->count())->toBe(1);
});

it('deduplicates pending unique jobs at dispatch time', function () {
    /** @example-id queues/unique-job */
    config(['queue.default' => 'database']);

    RefreshProductAvailability::dispatch(1);
    expect(DB::table('jobs')->count())->toBe(1);

    // While the first is pending, a second dispatch for the SAME resource
    // is absorbed (ShouldBeUnique's cache lock).
    RefreshProductAvailability::dispatch(1);
    expect(DB::table('jobs')->count())->toBe(1);

    // A different resource dispatches normally — the dedupe is scoped by
    // uniqueId(), not global.
    RefreshProductAvailability::dispatch(2);
    expect(DB::table('jobs')->count())->toBe(2);
});
