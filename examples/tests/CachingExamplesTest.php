<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MLE\Examples\Tests\Fixtures\Caching;

/**
 * Executed claims for chapters/data/caching.md.
 */
it('caches a read with a bounded TTL and invalidates explicitly', function () {
    /** @example-id caching/remember-invalidate */
    $resolverCalls = 0;
    $fixture = new Caching(Cache::store());

    $resolver = function () use (&$resolverCalls) {
        $resolverCalls++;

        return ['featured' => $resolverCalls];
    };

    $first = $fixture->featured($resolver);
    $second = $fixture->featured($resolver);

    expect($resolverCalls)->toBe(1);
    expect($second)->toBe($first);

    $fixture->invalidateFeatured();
    $fixture->featured($resolver);

    expect($resolverCalls)->toBe(2);
});

it('serves stale-while-revalidate within the flexible window', function () {
    /** @example-id caching/flexible-swr */
    // Cache::flexible() exists since Laravel 11.9 — the claim is
    // version-gated and the harness skips below it, matching the
    // chapter's version table.
    if (version_compare(app()->version(), '11.9.0', '<')) {
        $this->markTestSkipped('Cache::flexible() requires Laravel 11.9+');
    }

    $resolverCalls = 0;
    $fixture = new Caching(Cache::store());

    $resolver = function () use (&$resolverCalls) {
        $resolverCalls++;

        return ['rate' => $resolverCalls];
    };

    expect($fixture->rates([300, 600], $resolver))->toBe(['rate' => 1]);

    // Inside the stale window: the OLD value is still served (the SWR
    // claim). Whether the background revalidation has already fired is
    // driver/termination-dependent, so only the served value is asserted.
    Carbon::setTestNow(now()->addSeconds(350));
    expect($fixture->rates([300, 600], $resolver))->toBe(['rate' => 1]);

    // Past the stale window: the value is gone and the resolver runs again.
    Carbon::setTestNow(now()->addSeconds(700));
    expect($fixture->rates([300, 600], $resolver))->toBe(['rate' => 2]);
    expect($resolverCalls)->toBe(2);
});

it('collapses a miss to one rebuilder with a fallback for the rest', function () {
    /** @example-id caching/stampede-lock */
    $fixture = new Caching(Cache::store());

    $rebuilds = 0;
    $rebuild = function () use (&$rebuilds) {
        $rebuilds++;

        return ['spotlight' => true];
    };

    // First caller rebuilds and caches.
    expect($fixture->spotlight($rebuild, fn () => ['fallback' => true]))->toBe(['spotlight' => true]);
    expect($rebuilds)->toBe(1);

    // Cached path: no rebuild.
    expect($fixture->spotlight($rebuild, fn () => ['fallback' => true]))->toBe(['spotlight' => true]);
    expect($rebuilds)->toBe(1);

    // A concurrent rebuilder holding the miss-lock sends this caller to
    // the fallback instead of stampeding the origin.
    Cache::forget('home:spotlight');
    $held = Cache::lock('lock:home:spotlight', 30);
    expect($held->acquire())->toBeTrue();

    expect($fixture->spotlight($rebuild, fn () => ['fallback' => true]))->toBe(['fallback' => true]);
    expect($rebuilds)->toBe(1);

    $held->release();
});

it('invalidates tag groups and requires a tag-capable store', function () {
    /** @example-id caching/tag-invalidation */
    $fixture = new Caching(Cache::store());

    $fixture->putPricing(1, ['usd' => 100]);
    $fixture->putPricing(2, ['usd' => 200]);

    expect(Cache::tags(['product:1'])->get('pricing:1'))->toBe(['usd' => 100]);

    $fixture->flushProduct(1);
    expect(Cache::tags(['product:1'])->get('pricing:1'))->toBeNull();
    expect(Cache::tags(['product:2'])->get('pricing:2'))->toBe(['usd' => 200]);

    $fixture->flushAllPricing();
    expect(Cache::tags(['pricing'])->get('pricing:2'))->toBeNull();

    // Driver contract: the harness runs on array (tag-capable). The chapter
    // documents that file/database/dynamodb stores do NOT support tags —
    // production config must assert this contract before shipping.
    expect(config('cache.default'))->toBe('array');
});

it('scopes cache keys per tenant so user data cannot leak', function () {
    /** @example-id caching/tenant-key-collision */
    $fixture = new Caching(Cache::store());

    $tenant1 = fn () => ['plan' => 'tenant-1'];
    $tenant2 = fn () => ['plan' => 'tenant-2'];

    $a = $fixture->tenantSettings(1, 10, $tenant1);
    $b = $fixture->tenantSettings(2, 10, $tenant2);

    // Different tenants, same user id: fully isolated values.
    expect($a)->toBe(['plan' => 'tenant-1']);
    expect($b)->toBe(['plan' => 'tenant-2']);

    // Both keys exist independently.
    expect(Cache::get('tenant:1:user:10:settings'))->toBe(['plan' => 'tenant-1']);
    expect(Cache::get('tenant:2:user:10:settings'))->toBe(['plan' => 'tenant-2']);
});

afterEach(function () {
    Carbon::setTestNow();
    Cache::flush();
});
