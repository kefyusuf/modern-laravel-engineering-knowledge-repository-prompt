<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Harness-local implementations of the transactions chapter patterns.
 * These encode the chapter's claims against the harness schema
 * (inventories, orders) instead of the chapters' App\ namespaces.
 */
final class Transactions
{
    /**
     * transactions/after-commit + transactions/short-transactions:
     * the state change commits in the transaction; the "external I/O"
     * (a queue dispatch standing in for it) happens after the commit.
     * Uses the database queue so dispatch timing is observable as rows.
     */
    public function placeOrder(int $skuId, int $quantity, int $customerId): int
    {
        $orderId = DB::transaction(function () use ($skuId, $quantity, $customerId) {
            $inventory = DB::table('inventories')->where('id', $skuId)->lockForUpdate()->first();

            if ($inventory === null || $inventory->available < $quantity) {
                throw new \RuntimeException('insufficient stock');
            }

            DB::table('inventories')->where('id', $skuId)->decrement('available', $quantity);

            return DB::table('orders')->insertGetId([
                'customer_id' => $customerId,
                'sku_id' => $skuId,
                'quantity' => $quantity,
                'status' => 'pending',
                'placed_at' => now(),
            ]);
        });

        // After the commit — the queue is outside the boundary.
        \MLE\Examples\Tests\Fixtures\Jobs\Shim::dispatch($orderId)->afterCommit();

        return $orderId;
    }

    /**
     * transactions/lost-update: atomic conditional decrement — the check
     * and the write are one statement, so no lost-update window exists.
     */
    public function atomicDecrement(int $skuId, int $quantity): bool
    {
        return DB::table('inventories')
            ->where('id', $skuId)
            ->where('available', '>=', $quantity)
            ->decrement('available', $quantity) > 0;
    }
}
