---
title: Caching
topic: caching
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
testbed: examples/tests/CachingExamplesTest.php
---

# Caching

Caching trades freshness for latency and load — a trade you must define per key:
what staleness is acceptable, what invalidates the entry, and what happens when a
hot key expires under load. The cache itself is the easy part; the invalidation
strategy and the stampede plan are the engineering.

## TL;DR

- **Use it when:** a read is expensive, recomputable, and its staleness can be
  bounded by TTL or event — aggregates, third-party responses, config, feature
  flags, denormalized read models.
- **Don't use it when:** the value is user-private (unless keys are scoped), the
  data is cheaper to compute than to invalidate, or staleness is a compliance
  incident rather than a product decision.
- **The classic production incident:** user-scoped data cached under a global key —
  user B sees user A's data. Second classic: a forever-TTL cache with no invalidation
  path, discovered only as "the site shows data from last Tuesday".

## Problem

Two failure classes dominate production caching bugs:

1. **Wrong data.** Stale beyond tolerance (no invalidation on some write path), or
   *someone else's* data (unscoped keys, cache poisoning via user input in the key).
2. **No data — all at once.** A hot key expires; a hundred requests all miss, all
   recompute, and the origin (database, third-party API) falls over. This is the
   cache stampede (dogpile), and it converts your cache from a load-shield into a
   synchronized hammer.

Both are strategy bugs, not framework bugs: Laravel's cache API will faithfully
implement whatever invalidation discipline you did or didn't design.

## When to use it

- Expensive aggregates or reports recomputed per request, with a stated freshness
  tolerance ("p95 must be < 50ms; data may be up to 60s stale").
- Third-party API responses with rate limits or latency you don't control.
- Config, settings, feature flags — high read rate, rare writes, cheap staleness.
- Denormalized read models maintained by events — the cache fronts the read model,
  it doesn't replace it ([CQRS](../architecture/cqrs.md)).

## When NOT to use it

- **User-private data without scoped keys.** If a key can ever be hit by another
  user/tenant, it's a data leak, not a cache.
- **Data cheaper to compute than to invalidate.** A primary-key lookup is already
  cached by your database; wrapping it adds a network hop and a second source of
  truth.
- **Write-heavy hot values.** A cache entry invalidated on every write is pure
  overhead; go to the database.
- **Durable storage.** Redis with `maxmemory` + an eviction policy will delete your
  keys under pressure — by design. Anything that must survive belongs in the
  database.
- **Responses that depend on per-request context** (auth state, locale, headers)
  unless that context is fully encoded in the key.

## The simplest correct implementation

A read-through cache with an explicit invalidation on the write path:

<!-- example: caching/remember-invalidate -->
```php
<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

final class ProductCatalog
{
    public function featured(int $limit = 10): array
    {
        // TTL is the staleness contract: 5 minutes, decided, documented.
        return Cache::remember('catalog:featured', now()->addMinutes(5), function () {
            return Product::query()
                ->where('featured', true)
                ->orderByDesc('sales_count')
                ->limit(10)
                ->get()
                ->toArray();
        });
    }

    public function invalidate(): void
    {
        Cache::forget('catalog:featured');
    }
}
```

```php
<?php

// On the write path — called AFTER the write commits (see below).
$this->catalog->invalidate();
```

That's the whole minimal pattern: bounded TTL, named key, invalidation owned by the
service that owns the data. Everything else in this chapter is what production adds
around it.

## What production requires

### Invalidate after the commit, not before

Invalidation fired inside a transaction can run before the write commits: a request
rebuilding the cache in that window re-caches the *old* value, which now outlives the
write (until TTL). Invalidation is a side effect — order it like one:

```php
<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    DB::table('products')->where('id', $id)->update(['featured' => true]);
});

// After COMMIT: safe to forget / re-warm.
Cache::forget('catalog:featured');
```

For queued side effects the same rule appears as `->afterCommit()`
([Transactions](../data/transactions.md)); for cache invalidation the event listener
or observer that clears keys should run post-commit.

### Stampede protection

When a hot key expires, N concurrent requests all recompute. Two complementary
fixes, both in the harness:

**Stale-while-revalidate** — serve the stale value to readers while one request
refreshes it. Laravel ships this as `Cache::flexible()` (since 11.23):

<!-- example: caching/flexible-swr -->
```php
<?php

use Illuminate\Support\Facades\Cache;

final class ExchangeRates
{
    public function get(): array
    {
        // Fresh for 5 minutes; may serve the stale value for another 10 while
        // a background refresh happens. Requires Laravel 11.23+.
        return Cache::flexible('rates:usd:eur', [300, 600], function () {
            return $this->fetchFromProvider(); // slow third-party call
        });
    }
}
```

**Lock-on-miss** — the portable pattern (works on Laravel 10 too): on miss, take a
short lock so one request rebuilds while the rest briefly wait or serve a fallback:

<!-- example: caching/stampede-lock -->
```php
<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;

final class HomepageSpotlight
{
    public function __construct(private readonly Repository $cache) {}

    public function get(): array
    {
        $key = 'home:spotlight';

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        // One process rebuilds; the rest either wait briefly or get a
        // cheap default. The lock TTL bounds the rebuild, the value TTL
        // bounds the staleness — two different jobs.
        $lock = $this->cache->lock("lock:{$key}", 30);
        try {
            $lock->block(0); // one rebuilder wins; the rest take the fallback path
            $value = $this->rebuild();
            $this->cache->put($key, $value, now()->addMinutes(10));

            return $value;
        } catch (LockTimeoutException) {
            return $this->fallback(); // last-known value or a cheap default
        } finally {
            $lock->release();
        }
    }
}
```

On a shared store (redis/memcached) this collapses the thundering herd to one
rebuilder; the fallback path is what keeps p99 flat during the rebuild.

### Tags and the driver contract

Tags let you invalidate a group (`Cache::tags(['products'])->flush()`), but they are
a **store capability**: redis, memcached, and array support them; the `file`,
`database`, and `dynamodb` stores do not. The classic deployment bug: tags work in
local development (array/file setups aside, tests run on array) and throw in
production on the database store — or worse, a driver switch silently changes
invalidation semantics.

<!-- example: caching/tag-invalidation -->
```php
<?php

use Illuminate\Support\Facades\Cache;

final class PricingCache
{
    public function put(int $productId, array $prices): void
    {
        Cache::tags(['pricing', "product:{$productId}"])
            ->put("pricing:{$productId}", $prices, now()->addHours(12));
    }

    public function flushProduct(int $productId): void
    {
        Cache::tags(["product:{$productId}"])->flush();
    }

    public function flushAll(): void
    {
        Cache::tags(['pricing'])->flush();
    }
}
```

If you need tag-like invalidation on a store without tags, encode groups in the key
with a generation number (`pricing:{gen}:{productId}` — flush by bumping `gen`),
which is portable but adds bookkeeping.

### Key design: scope, namespacing, versioning

- **Scope first:** anything user- or tenant-specific is in the key:
  `tenant:{id}:user:{id}:settings`. No exceptions.
- **Namespace by owner:** `module:concept:id` (`billing:invoice:{id}:totals`) so
  ownership and grep-ability are obvious — see
  [The modular monolith](../architecture/modular-monolith.md) for per-module key
  ownership.
- **Hash variable parts:** don't embed raw user input in keys (unbounded length,
  collision/abuse); hash it.
- **Version on schema change:** bump a prefix (`v2:catalog:featured`) when the
  *shape* changes, so old and new code never collide during a deploy.

<!-- example: caching/tenant-key-collision -->
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class TenantSettings
{
    public function get(int $tenantId, int $userId): array
    {
        // The tenant/user ids are structural, verified, non-user-input.
        return Cache::remember(
            "tenant:{$tenantId}:user:{$userId}:settings",
            now()->addMinutes(15),
            fn () => $this->fetchSettings($tenantId, $userId),
        );
    }
}
```

### What you store matters

Cache **plain values, arrays, or DTOs** — not Eloquent models. A serialized model
rehydrates without its relations, carries stale partial state, and silently breaks
when attributes change. Store exactly the shape the
consumer needs, and version the key when the shape changes.

### Octane caveat

Under Octane, each worker process is long-lived: the `array` store is per-worker
(values written in worker 1 are invisible to worker 2), and any static property used
as a cache diverges the same way. If a value must be consistent across workers, it
belongs in a shared store — redis — not in process memory. The general lesson:
"the cache" is whichever store is configured; code that assumes one store's
semantics will betray you on another (Octane — roadmap).

## What usually goes wrong

1. **Cross-tenant (or cross-user) key collision.** *Root cause:* user-scoped data
   cached under a global key — often after a refactor moved scoping out of the key.
   *Detection:* support reports of "seeing someone else's data"; access logs show
   reads without a matching write. *Fix:* scoped keys as a hard rule + a review
   gate on key naming; consider an architecture test that greps cache writes for
   missing tenant segments.
2. **Stampede on hot key expiry.** *Root cause:* single TTL, no revalidation strategy;
   the herd hits the origin on expiry. *Detection:* origin latency/traffic spiking
   periodically at TTL multiples; hit-ratio cliff then thundering misses. *Fix:*
   `flexible()` or lock-on-miss + jittered TTLs so keys don't expire in sync.
3. **Stale forever.** *Root cause:* a write path that forgets to invalidate; TTL was
   set to "basically forever". *Detection:* staleness complaints; a diff between
   source-of-truth and cache older than the business tolerance. *Fix:* every cache
   has an owner service; invalidation is called from the write path after commit;
   TTL as a hard backstop.
4. **Tags on a store without tag support.** *Root cause:* dev on array/redis, prod
   on database/file. *Detection:* `BadMethodCallException`/logic exceptions on
   deploy, or invalidation silently not grouping. *Fix:* a config-assertion test
   that the production store supports every cache feature the code uses.
5. **Cached Eloquent models.** *Root cause:* models cached, then relations assumed;
   hydration differs, attributes drift. *Detection:* intermittent "property on
   null"/missing-relation errors only for cache hits. *Fix:* store arrays/DTOs;
   version keys on shape change.
6. **Treating Redis as durable.** *Root cause:* session-ish or workflow state kept
   only in cache; maxmemory eviction deletes it. *Detection:* data that vanishes
   under memory pressure. *Fix:* durable state in the database; cache holds
   recomputable values only.

## How to test it

- **TTL/freshness:** freeze time (`Carbon::setTestNow`), assert fresh-then-stale
  boundaries — including the `flexible()` fresh/stale window (⚙️
  `examples/tests/CachingExamplesTest.php`).
- **Stampede:** two workers against the same store, both hitting an expired key —
  assert one rebuild, one fallback/wait (deterministic version of the herd).
- **Invalidation:** write → assert key gone / next read recomputes; assert it works
  when the write happens inside a transaction that commits.
- **Driver contract:** assert the configured production store supports tags/locks
  where the code uses them (a config test, not a cache test).
- **Scope:** an architecture-level test asserting cache keys for tenant-scoped
  services contain the tenant id (grep-level check in CI).

## How to operate it

- **Hit ratio is the metric that matters** — but segment it: a 95% hit ratio with the
  5% misses hammering a slow origin is worse than a flatter 85%. Track misses by key
  prefix and alert on *miss rate × origin cost*, not the ratio alone.
- **Redis hygiene:** `maxmemory` + `maxmemory-policy` (choose deliberately:
  `allkeys-lru` evicts anything; `volatile-*` only evicts keys with TTLs — mixing
  durable and cache data in one instance is a design smell), big-key scans
  (`redis-cli --bigkeys`), fragmentation.
- **Stale tags:** if you use tags on redis, schedule `cache:prune-stale-tags` so
  tag bookkeeping doesn't accumulate.
- **3am runbook:** origin overload → check for synchronized expiry (TTL histogram),
  roll out jitter/lock-on-miss; leak report → find the key pattern, flush the scope,
  fix the key; eviction storm → maxmemory policy + key inventory.

## How it scales

- **Redis is a single point of contention and of failure** for the read path. Plan:
  memory headroom, eviction policy, and a degradation path that works when the
  cache is *down* (fail open to the origin with rate limiting, rather than 500s).
- **Replication gives read scale but breaks read-your-writes** on the replica;
  consistency-critical reads go to the primary or stay on the cache.
- **Clustering** splits keys across shards — multi-key operations (tags are
  multi-key!) constrain your key design; tag groups and hashes must co-locate.
- **Beyond a cache:** if invalidation correctness keeps costing you, the deeper fix
  is a denormalized read model maintained by events
  ([CQRS](../architecture/cqrs.md)) with the cache as its front — the read model
  gives you a rebuildable source, the cache only accelerates it.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `Cache::flexible()` stale-while-revalidate | ❌ not available | ✅ since 11.23 | ✅ | ✅ |
| Cache tags on redis/memcached/array | ✅ | ✅ | ✅ | ✅ |
| Cache tags on file/database/dynamodb stores | ❌ | ❌ | ❌ | ❌ |
| `cache:prune-stale-tags` | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The `flexible()` row is the only behavioral fork that affects this chapter's code —
the lock-on-miss example exists precisely as the Laravel-10-compatible equivalent
(flexible requires 11.23; the lock-on-miss pattern needs nothing newer than
Laravel 10).

## Related

- [Locking](../data/locking.md) — stampede protection is an atomic lock application;
  ownership and TTL rules carry over.
- [Transactions](../data/transactions.md) — invalidation ordering vs the commit.
- [Queues](../messaging/queues.md) — async revalidation and warmup jobs.
- [Idempotency](../messaging/idempotency.md) — cache as fast-path dedupe only;
  the guarantee lives in the database.
- [The modular monolith](../architecture/modular-monolith.md) — per-module key
  ownership and invalidation contracts.
- Covered since this line was written: [Redis as infrastructure](redis.md) and
  [Octane](../runtime/octane.md).
