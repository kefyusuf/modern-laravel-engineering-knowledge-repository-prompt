<?php

use Illuminate\Support\Facades\Cache;

uses()->group('redis');

/**
 * Executed claims for chapters/data/redis.md.
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

    // A known framework bug breaks TAG FLUSH on the Laravel 10 + phpredis
    // combination (laravel/framework#54060: the zscan over the tag key is
    // not executed under phpredis 6.x — the flush is a silent no-op; the
    // matrix caught exactly this). Skip that combination with the issue
    // cited; the predis client (what extension-less environments run) and
    // Laravel 11+/12+/13+ with phpredis flush correctly.
    $phpredis = config('database.redis.client') === 'phpredis';
    $onLaravel10 = version_compare(app()->version(), '11.0.0', '<');
    if ($phpredis && $onLaravel10) {
        $this->markTestSkipped('Tag flush is broken on Laravel 10 + phpredis (laravel/framework#54060) — verified by the CI matrix.');
    }

    $store = Cache::store('redis');

    $store->tags(['harness-pricing'])->put('harness:pricing:1', ['usd' => 100], now()->addMinutes(10));
    $store->tags(['harness-pricing'])->put('harness:pricing:2', ['usd' => 200], now()->addMinutes(10));

    // Reads must use the SAME tag set the write used.
    expect($store->tags(['harness-pricing'])->get('harness:pricing:1'))->toBe(['usd' => 100]);

    // Group invalidation removes every member.
    $store->tags(['harness-pricing'])->flush();
    expect($store->tags(['harness-pricing'])->get('harness:pricing:1'))->toBeNull();
    expect($store->tags(['harness-pricing'])->get('harness:pricing:2'))->toBeNull();
});
