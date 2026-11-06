<?php

namespace MLE\Examples\Tests\Fixtures\Testing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * testing/failure-injection: a job whose dependency fails, with a retry
 * policy that gives up immediately — so one worker cycle lands it in
 * failed_jobs and fires failed().
 */
final class BrittleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public static int $failureNotifications = 0;

    public function handle(): void
    {
        // Dependency failure injected at the seam: the job's contract
        // (type-hinted dependency) is replaced by the test.
        throw new \RuntimeException('downstream unavailable');
    }

    public function failed(\Throwable $e): void
    {
        self::$failureNotifications++;
    }
}
