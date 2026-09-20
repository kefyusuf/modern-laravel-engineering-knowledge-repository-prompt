<?php

namespace MLE\Examples\Tests\Fixtures\Queues;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** queues/basic-job — id-based serialization + re-run guard. */
final class SendOrderInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = DB::table('orders')->where('id', $this->orderId)->first();

        if ($order === null || $order->invoiced_at !== null) {
            return; // deleted or already invoiced: not an error
        }

        DB::table('orders')->where('id', $this->orderId)->update(['invoiced_at' => now()]);
    }
}
