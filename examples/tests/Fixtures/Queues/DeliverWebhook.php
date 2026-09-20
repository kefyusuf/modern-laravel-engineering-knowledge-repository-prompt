<?php

namespace MLE\Examples\Tests\Fixtures\Queues;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** queues/retry-policy — declared policy + failure hook. */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $maxExceptions = 3;

    public static int $failureNotifications = 0;

    public function backoff(): array
    {
        return [10, 30, 90, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(
        public readonly int $endpointId,
        public readonly string $payloadId,
    ) {}

    public function handle(): void
    {
        throw new \RuntimeException('downstream unavailable');
    }

    public function failed(\Throwable $e): void
    {
        self::$failureNotifications++;
    }
}
