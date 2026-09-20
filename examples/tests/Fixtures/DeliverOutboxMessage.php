<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/** outbox/inbox-consumer — the delivery job with consumer-side dedupe. */
final class DeliverOutboxMessage
{
    /** @var int[] pending "queue" of outbox message ids */
    public static array $queue = [];

    /** @var string[] effects applied by the (simulated) consumer handler */
    public static array $handlerEffects = [];

    /** Stands in for the queue driver: enqueue now, process later. */
    public static function dispatch(int $messageId): void
    {
        self::$queue[] = $messageId;
    }

    /** Stands in for a worker: drain the queue (job runs AFTER the mark). */
    public static function processQueue(): void
    {
        $pending = self::$queue;
        self::$queue = [];

        foreach ($pending as $messageId) {
            self::handle($messageId);
        }
    }

    public static function handle(int $messageId): void
    {
        $message = OutboxMessage::query()->find($messageId);

        if ($message === null || $message->dispatched_at === null) {
            return;
        }

        $claimed = DB::table('processed_messages')->insertOrIgnore([
            'source' => 'outbox',
            'message_key' => (string) $message->id,
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return; // duplicate delivery — absorbed by the inbox
        }

        self::$handlerEffects[] = $message->event_type.':'.$message->id;
    }
}
