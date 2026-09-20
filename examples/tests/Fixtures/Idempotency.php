<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Harness-local implementations of the idempotency chapter patterns.
 */
final class Idempotency
{
    /** idempotency/dedupe-unique-index — the minimal layer-3 pattern. */
    public function applyPointsGrant(string $source, string $messageKey, int $userId, int $points): void
    {
        DB::transaction(function () use ($source, $messageKey, $userId, $points) {
            $claimed = DB::table('processed_messages')->insertOrIgnore([
                'source' => $source,
                'message_key' => $messageKey,
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return;
            }

            DB::table('wallets')->where('user_id', $userId)->increment('points', $points);
        });
    }

    /** idempotency/consumer-dedupe — full consumer flow with explicit unknown-subject handling. */
    public function handlePaymentSucceeded(string $source, string $messageKey, string $externalSubscriptionId): void
    {
        DB::transaction(function () use ($source, $messageKey, $externalSubscriptionId) {
            $claimed = DB::table('processed_messages')->insertOrIgnore([
                'source' => $source,
                'message_key' => $messageKey,
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return;
            }

            $subscription = DB::table('subscriptions')
                ->where('external_id', $externalSubscriptionId)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                throw new \RuntimeException('unknown subscription: event out of order?');
            }

            DB::table('subscriptions')
                ->where('id', $subscription->id)
                ->update(['status' => 'active', 'paid_until' => now()->addMonth()]);
        });
    }

    /**
     * idempotency/api-idempotency-key — Stripe-style key claim: unique
     * (scope, key), fingerprint check, replay of the stored response.
     * Returns [status, body-array, replay-header?].
     *
     * @return array{0: int, 1: array, 2: bool}
     */
    public function createWithKey(string $scope, string $key, string $fingerprint, callable $action): array
    {
        return DB::transaction(function () use ($scope, $key, $fingerprint, $action) {
            $claimed = DB::table('idempotency_keys')->insertOrIgnore([
                'scope' => $scope,
                'key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => 'in_flight',
                'created_at' => now(),
            ]);

            $existing = DB::table('idempotency_keys')
                ->where('scope', $scope)->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($claimed === 0 && $existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    return [422, ['error' => 'idempotency_key_reused'], false];
                }
                if ($existing->status === 'completed') {
                    return [200, json_decode($existing->response_body, true), true];
                }

                return [409, ['error' => 'request_in_progress'], false];
            }

            $result = $action();

            DB::table('idempotency_keys')
                ->where('scope', $scope)->where('key', $key)
                ->update(['status' => 'completed', 'response_body' => json_encode($result)]);

            return [200, $result, false];
        });
    }

    /** idempotency/upsert-race — natural-key upsert, idempotent by construction. */
    public function syncSubscription(string $externalId, string $status): void
    {
        DB::table('subscriptions')->upsert(
            [['external_id' => $externalId, 'status' => $status]],
            ['external_id'],
            ['status'],
        );
    }
}
