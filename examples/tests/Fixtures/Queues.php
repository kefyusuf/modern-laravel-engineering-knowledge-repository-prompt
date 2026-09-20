<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Harness-local implementations of the queues chapter patterns.
 */
final class Queues
{
    /**
     * queues/after-commit-dispatch: dispatch inside the transaction with
     * afterCommit; the job row (database queue) may exist only after COMMIT.
     */
    public function completeOrder(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            DB::table('orders')->where('id', $orderId)->update(['status' => 'completed']);

            Queues\SendOrderInvoice::dispatch($orderId)->afterCommit();
        });
    }
}
