<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Harness-local implementation of the CQRS projection pattern.
 */
final class Cqrs
{
    /**
     * cqrs/projection-listener — recompute-and-write projection update:
     * idempotent under redelivery (a duplicate event recomputes the same
     * value instead of double-counting).
     */
    public function updateMerchantShipmentProjection(array $payload): void
    {
        $total = DB::table('orders')
            ->where('merchant_id', $payload['merchant_id'])
            ->where('status', 'shipped')
            ->count();

        DB::table('merchant_shipment_totals')->updateOrInsert(
            ['merchant_id' => $payload['merchant_id']],
            ['shipments_total' => $total, 'updated_at' => now()],
        );
    }

    /** Level-1 query object: reads return the consumer's shape. */
    public function orderTotalsByStatus(int $merchantId, string $status): array
    {
        return DB::table('orders')
            ->where('merchant_id', $merchantId)
            ->where('status', $status)
            ->get(['id', 'quantity'])
            ->all();
    }
}
