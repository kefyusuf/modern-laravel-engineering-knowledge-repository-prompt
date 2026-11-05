<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Idempotency;

uses()->group('idempotency');

/**
 * Executed claims for chapters/messaging/idempotency.md.
 */
it('makes duplicate deliveries harmless with a unique index claim', function () {
    /** @example-id idempotency/dedupe-unique-index */
    DB::table('wallets')->insert(['user_id' => 1, 'points' => 0]);

    $fixture = new Idempotency;

    $fixture->applyPointsGrant('loyalty', 'evt-1', 1, 50);
    expect(DB::table('wallets')->where('user_id', 1)->value('points'))->toBe(50);

    // The retried delivery: dedupe row exists, effect does not re-apply.
    $fixture->applyPointsGrant('loyalty', 'evt-1', 1, 50);
    expect(DB::table('wallets')->where('user_id', 1)->value('points'))->toBe(50);

    // A different event applies normally.
    $fixture->applyPointsGrant('loyalty', 'evt-2', 1, 10);
    expect(DB::table('wallets')->where('user_id', 1)->value('points'))->toBe(60);
});

it('dedupes webhook consumers and rejects unknown subjects loudly', function () {
    /** @example-id idempotency/consumer-dedupe */
    DB::table('subscriptions')->insert(['external_id' => 'sub_1', 'status' => 'pending']);

    $fixture = new Idempotency;

    $fixture->handlePaymentSucceeded('stripe', 'evt_1', 'sub_1');
    expect(DB::table('subscriptions')->where('external_id', 'sub_1')->value('status'))->toBe('active');

    // Duplicate delivery: acknowledged, no double effect.
    $fixture->handlePaymentSucceeded('stripe', 'evt_1', 'sub_1');
    expect(DB::table('subscriptions')->where('external_id', 'sub_1')->value('status'))->toBe('active');

    // Unknown subject with a FRESH event id: an error, so the queue can
    // retry after the earlier event arrives (out-of-order detection).
    expect(fn () => $fixture->handlePaymentSucceeded('stripe', 'evt_2', 'sub_missing'))
        ->toThrow(RuntimeException::class);
    expect(DB::table('processed_messages')->where('message_key', 'evt_2')->exists())->toBeFalse();
});

it('replays stored responses for idempotency keys and rejects key reuse', function () {
    /** @example-id idempotency/api-idempotency-key */
    $fixture = new Idempotency;

    $actions = 0;
    $action = function () use (&$actions) {
        $actions++;

        return ['order_id' => 7];
    };

    // First attempt: claims the key, runs, stores the response.
    [$status, $body, $replay] = $fixture->createWithKey('orders:create', 'key-1', 'fp-1', $action);
    expect($status)->toBe(200);
    expect($body)->toBe(['order_id' => 7]);
    expect($replay)->toBeFalse();
    expect($actions)->toBe(1);

    // Network retry with the SAME key + payload: replay, no second effect.
    [$status, $body, $replay] = $fixture->createWithKey('orders:create', 'key-1', 'fp-1', $action);
    expect($status)->toBe(200);
    expect($body)->toBe(['order_id' => 7]);
    expect($replay)->toBeTrue();
    expect($actions)->toBe(1);

    // Same key, different payload: a client bug — 422, no effect.
    [$status, $body] = $fixture->createWithKey('orders:create', 'key-1', 'fp-different', $action);
    expect($status)->toBe(422);
    expect($actions)->toBe(1);

    // A fresh key is a fresh operation.
    [$status] = $fixture->createWithKey('orders:create', 'key-2', 'fp-1', $action);
    expect($status)->toBe(200);
    expect($actions)->toBe(2);
});

it('converges natural-key upserts instead of racing check-then-create', function () {
    /** @example-id idempotency/upsert-race */
    $fixture = new Idempotency;

    // Two sync events for the same natural key, in either order or
    // duplicated: the table converges to one row with the latest state.
    $fixture->syncSubscription('ext_1', 'active');
    $fixture->syncSubscription('ext_1', 'cancelled');

    $rows = DB::table('subscriptions')->where('external_id', 'ext_1')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->status)->toBe('cancelled');
});
