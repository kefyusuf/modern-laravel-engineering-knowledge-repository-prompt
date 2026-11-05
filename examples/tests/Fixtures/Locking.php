<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Harness-local implementations of the locking chapter patterns.
 */
final class Locking
{
    /**
     * locking/db-lock-for-update: transaction + row lock + unique-index
     * backstop (the unique index lives on orders via the harness migration).
     */
    public function purchase(int $skuId, int $quantity, int $customerId): int
    {
        return DB::transaction(function () use ($skuId, $quantity, $customerId) {
            $inventory = DB::table('inventories')->where('id', $skuId)->lockForUpdate()->first();

            if ($inventory === null || $inventory->available < $quantity) {
                throw new \RuntimeException('insufficient stock');
            }

            DB::table('inventories')->where('id', $skuId)->decrement('available', $quantity);

            return DB::table('orders')->insertGetId([
                'customer_id' => $customerId,
                'sku_id' => $skuId,
                'quantity' => $quantity,
                'status' => 'active',
                'placed_at' => now(),
            ]);
        });
    }

    /**
     * locking/without-overlapping + locking/lock-scope-mismatch: the scope
     * contract — mutex keys derived from the resource identity serialize
     * same-resource workers and stay independent across different resources.
     * This is the claim the WithoutOverlapping middleware relies on (its
     * mutex is a cache lock with the derived key).
     *
     * @return array{0: bool, 1: bool}
     */
    public function scopeContract(string $keyA, string $keyB): array
    {
        $lockA = Cache::lock($keyA, 30);
        $lockB = Cache::lock($keyB, 30);

        $aAcquired = $lockA->acquire();
        $bAcquired = $lockB->acquire();

        $lockA->release();
        $lockB->release();

        return [$aAcquired, $bAcquired];
    }
}
