<?php

namespace MLE\Examples\Tests\Fixtures;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Harness-local implementations of the caching chapter patterns.
 */
final class Caching
{
    public function __construct(private readonly Repository $cache) {}

    /** caching/remember-invalidate */
    public function featured(callable $resolver): mixed
    {
        return Cache::remember('catalog:featured', now()->addMinutes(5), $resolver);
    }

    public function invalidateFeatured(): void
    {
        Cache::forget('catalog:featured');
    }

    /** caching/flexible-swr — Laravel 11.9+ only (skipped below that). */
    public function rates(array $ttls, callable $resolver): mixed
    {
        return Cache::flexible('rates:usd:eur', $ttls, $resolver);
    }

    /** caching/stampede-lock — lock-on-miss with fallback. */
    public function spotlight(callable $rebuild, callable $fallback): mixed
    {
        $key = 'home:spotlight';

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $lock = $this->cache->lock("lock:{$key}", 30);
        try {
            $lock->block(0);
            $value = $rebuild();
            $this->cache->put($key, $value, now()->addMinutes(10));

            return $value;
        } catch (LockTimeoutException) {
            return $fallback();
        } finally {
            $lock->release();
        }
    }

    /** caching/tag-invalidation */
    public function putPricing(int $productId, array $prices): void
    {
        Cache::tags(['pricing', "product:{$productId}"])
            ->put("pricing:{$productId}", $prices, now()->addHours(12));
    }

    public function flushProduct(int $productId): void
    {
        Cache::tags(["product:{$productId}"])->flush();
    }

    public function flushAllPricing(): void
    {
        Cache::tags(['pricing'])->flush();
    }

    /** caching/tenant-key-collision — scoped keys keep tenants isolated. */
    public function tenantSettings(int $tenantId, int $userId, callable $resolver): mixed
    {
        return Cache::remember(
            "tenant:{$tenantId}:user:{$userId}:settings",
            now()->addMinutes(15),
            $resolver,
        );
    }
}
