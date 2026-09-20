<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Harness-local implementation of the outbox chapter pattern.
 *
 * SQLite (the harness database) has no SKIP LOCKED, so the relay here runs
 * in single-relay mode with plain lockForUpdate() — the concurrency claim
 * (SKIP LOCKED on MySQL 8+/Postgres) is a database capability documented in
 * the chapter, not a harness-verifiable behavior.
 */
final class Outbox
{
    public function publish(string $eventType, int $aggregateId, array $payload): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Outbox::publish() must be called inside a transaction.');
        }

        DB::table('outbox_messages')->insert([
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'aggregate_id' => $aggregateId,
            'created_at' => now(),
        ]);
    }

    /**
     * outbox/relay-claim: claim → dispatch → mark in ONE transaction. Crash
     * before commit leaves rows pending (at-least-once); concurrent relays
     * claim disjoint rows where the database supports SKIP LOCKED.
     *
     * @return int[] dispatched message ids
     */
    public function relay(int $limit = 100): array
    {
        $dispatched = [];

        DB::transaction(function () use ($limit, &$dispatched) {
            $claimed = OutboxMessage::query()
                ->whereNull('dispatched_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($claimed as $message) {
                DeliverOutboxMessage::dispatch($message->id);
                $dispatched[] = $message->id;
            }

            if ($claimed->isNotEmpty()) {
                OutboxMessage::query()
                    ->whereIn('id', $claimed->pluck('id'))
                    ->update(['dispatched_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
            }
        });

        return $dispatched;
    }
}
