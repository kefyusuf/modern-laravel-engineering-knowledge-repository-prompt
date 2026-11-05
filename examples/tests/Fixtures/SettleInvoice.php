<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * observability/job-context — context added before dispatch is re-hydrated
 * when the job processes; the job attributes its logs to the origin request.
 */
final class SettleInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static ?string $capturedTenant = null;

    public function __construct(public readonly int $invoiceId) {}

    public function handle(): void
    {
        Log::withContext(Context::all());

        // The behavioral claim under test: context survived the queue hop.
        self::$capturedTenant = Context::get('tenant_id');
    }
}
