<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\DeliverOutboxMessage;
use MLE\Examples\Tests\Fixtures\Outbox;
use MLE\Examples\Tests\Fixtures\OutboxMessage;

uses()->group('outbox');

/**
 * Executed claims for chapters/messaging/outbox.md.
 *
 * SQLite note: the harness relay uses single-relay mode (plain
 * lockForUpdate) because SQLite lacks SKIP LOCKED — the concurrent-relay
 * claim is a database capability documented in the chapter.
 */
it('commits the outbox row atomically with the state change', function () {
    /** @example-id outbox/outbox-table */
    $outbox = new Outbox;

    // Inside the transaction: the message exists after COMMIT.
    DB::transaction(function () use ($outbox) {
        DB::table('orders')->insert([
            'id' => 1, 'customer_id' => 1, 'sku_id' => 1,
            'status' => 'shipped', 'placed_at' => now(),
        ]);
        $outbox->publish('order.shipped', 1, ['schema_version' => 1, 'order_id' => 1]);
    });

    expect(OutboxMessage::query()->where('aggregate_id', 1)->count())->toBe(1);

    // Rolling back the state change rolls back the message: no phantom
    // events for state that never existed.
    try {
        DB::transaction(function () use ($outbox) {
            $outbox->publish('order.shipped', 2, ['schema_version' => 1]);
            throw new RuntimeException('state change failed');
        });
    } catch (RuntimeException) {
    }
    expect(OutboxMessage::query()->where('aggregate_id', 2)->count())->toBe(0);

    // Publishing outside a transaction is a loud error, not a silent
    // dual-write.
    expect(fn () => $outbox->publish('order.shipped', 3, []))
        ->toThrow(LogicException::class);
});

it('relays pending messages exactly once per relay and marks them', function () {
    /** @example-id outbox/relay-claim */
    $outbox = new Outbox;
    DeliverOutboxMessage::$handlerEffects = [];

    DB::transaction(function () use ($outbox) {
        $outbox->publish('order.shipped', 10, ['order_id' => 10]);
        $outbox->publish('order.shipped', 11, ['order_id' => 11]);
    });

    $dispatched = $outbox->relay();
    DeliverOutboxMessage::processQueue();

    expect($dispatched)->toBe([1, 2]); // id order
    expect(DeliverOutboxMessage::$handlerEffects)->toBe(['order.shipped:1', 'order.shipped:2']);
    expect(OutboxMessage::query()->whereNull('dispatched_at')->count())->toBe(0);
    expect(OutboxMessage::query()->where('attempts', 1)->count())->toBe(2);

    // A second relay finds nothing pending — no duplicate effects.
    expect($outbox->relay())->toBe([]);
    DeliverOutboxMessage::processQueue();
    expect(DeliverOutboxMessage::$handlerEffects)->toBe(['order.shipped:1', 'order.shipped:2']);
});

it('absorbs relay duplicates in the consumer inbox', function () {
    /** @example-id outbox/inbox-consumer */
    $outbox = new Outbox;
    DeliverOutboxMessage::$handlerEffects = [];

    DB::transaction(fn () => $outbox->publish('order.shipped', 20, ['order_id' => 20]));
    $outbox->relay();
    DeliverOutboxMessage::processQueue();
    expect(DeliverOutboxMessage::$handlerEffects)->toHaveCount(1);

    // Simulate the crash window: the delivery happened, but the "mark
    // dispatched" update was lost. The relay runs again on the same row.
    OutboxMessage::query()->where('id', 1)->update(['dispatched_at' => null]);
    $outbox->relay();
    DeliverOutboxMessage::processQueue();

    // The producer delivered twice (at-least-once, by design) — and the
    // inbox dedupe reduced it to exactly one effect.
    expect(DeliverOutboxMessage::$handlerEffects)->toHaveCount(1);
    expect(DB::table('processed_messages')->where([
        'source' => 'outbox',
        'message_key' => '1',
    ])->count())->toBe(1);
});
