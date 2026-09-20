<?php

namespace MLE\Examples\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal dispatchable standing in for "the external side effect" in
 * transaction-boundary tests. Carries only a reference id, as the
 * chapters prescribe.
 */
final class Shim implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $referenceId) {}

    public function handle(): void
    {
        // The harness never processes this job; dispatch timing is the claim.
    }
}
