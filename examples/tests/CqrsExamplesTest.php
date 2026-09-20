<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Cqrs;

/**
 * Executed claims for chapters/architecture/cqrs.md.
 */
it('maintains the projection via recompute-and-write, absorbing redeliveries', function () {
    /** @example-id cqrs/projection-listener */
    $fixture = new Cqrs;
    $event = fn (int $merchantId) => ['merchant_id' => $merchantId];

    $seedShipped = fn (int $merchantId) => DB::table('orders')->insert([
        'merchant_id' => $merchantId,
        'status' => 'shipped',
        'quantity' => 1,
    ]);
    $seedPending = fn (int $merchantId) => DB::table('orders')->insert([
        'merchant_id' => $merchantId,
        'status' => 'pending',
        'quantity' => 1,
    ]);

    $seedShipped(1);
    $seedPending(1);

    $fixture->updateMerchantShipmentProjection($event(1));

    $row = DB::table('merchant_shipment_totals')->where('merchant_id', 1)->first();
    expect($row->shipments_total)->toBe(1);

    // New shipped state → the recompute reflects it.
    $seedShipped(1);
    $fixture->updateMerchantShipmentProjection($event(1));
    expect(DB::table('merchant_shipment_totals')->where('merchant_id', 1)->value('shipments_total'))->toBe(2);

    // At-least-once redelivery of the SAME event: recompute-and-write is
    // idempotent — the increment pattern would now read 3.
    $fixture->updateMerchantShipmentProjection($event(1));
    expect(DB::table('merchant_shipment_totals')->where('merchant_id', 1)->value('shipments_total'))->toBe(2);

    // Other merchants are isolated.
    $seedShipped(2);
    $fixture->updateMerchantShipmentProjection($event(2));
    expect(DB::table('merchant_shipment_totals')->where('merchant_id', 2)->value('shipments_total'))->toBe(1);
});

it('returns reads in the consumer shape, separate from the write model', function () {
    $fixture = new Cqrs;

    DB::table('orders')->insert([
        ['merchant_id' => 1, 'status' => 'shipped', 'quantity' => 3],
        ['merchant_id' => 1, 'status' => 'pending', 'quantity' => 1],
    ]);

    $summaries = $fixture->orderTotalsByStatus(1, 'shipped');

    expect($summaries)->toHaveCount(1);
    expect($summaries[0]->quantity)->toBe(3);
});
