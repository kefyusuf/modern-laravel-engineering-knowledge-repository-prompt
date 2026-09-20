<?php

namespace MLE\Examples\Tests\Fixtures\Queues;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/** queues/unique-job — dispatch-time dedupe via ShouldBeUnique. */
final class RefreshProductAvailability implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("availability:{$this->productId}"))->expireAfter(300),
        ];
    }

    public function handle(): void
    {
        // The harness never processes this job; dispatch dedupe is the claim.
    }
}
