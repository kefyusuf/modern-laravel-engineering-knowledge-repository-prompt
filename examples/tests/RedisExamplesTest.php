<?php

use Illuminate\Support\Facades\Cache;

uses()->group('redis');

/**
 * Executed claims for chapters/data/redis.md.
 *
 * These tests call redisRequired() and SKIP when no Redis server is
 * reachable (local laptops); CI's Redis service container runs them for
 * real. The array-store logic claims live in the Caching/Locking tests —
 * these verify the STORE.
 */
it('grants exclusive locks on the real redis store', function () {
    /** @example-id redis/lock-semantics */
    $this->redisRequired();

    $store = Cache::store('redis');

    $lock = $store->lock('harness:lock:semantics', 30);
    expect($lock->acquire())->toBeTrue();

    // Second holder excluded while the lock lives.
    $held = $store->lock('harness:lock:semantics', 30);
    expect($held->acquire())->toBeFalse();

    // Owner-checked release frees it for the next worker.
    $lock->release();
    expect($held->acquire())->toBeTrue();
    $held->release();
});

it('supports tag-based invalidation on the real redis store', function () {
    /** @example-id redis/tag-invalidation */
    $this->redisRequired();

    $store = Cache::store('redis');

    $store->tags(['harness-pricing'])->put('harness:pricing:1', ['usd' => 100], now()->addMinutes(10));
    $store->tags(['harness-pricing'])->put('harness:pricing:2', ['usd' => 200], now()->addMinutes(10));

    expect($store->tags(['harness-pricing'])->get('harness:pricing:1'))->toBe(['usd' => 100]);

    // Group invalidation removes every member.
    $store->tags(['harness-pricing'])->flush();
    expect($store->tags(['harness-pricing'])->get('harness:pricing:1'))->toBeNull();
    expect($store->tags(['harness-pricing'])->get('harness:pricing:2'))->toBeNull();
});
