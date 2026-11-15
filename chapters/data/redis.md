---
title: Redis as infrastructure
topic: redis
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/RedisExamplesTest.php
---

# Redis as infrastructure

By the time Redis is load-bearing for your application — cache, locks, queue
backing, rate limiting — it is not a cache server anymore; it is a stateful
single point of failure with its own operations discipline. This chapter covers
the decisions that matter once Redis is infrastructure: client choice, memory
and eviction policy, durability expectations, and the failure modes that turn
"the cache is down" into "the app is down".

## TL;DR

- **Use it when:** shared state across many app servers needs sub-millisecond
  access — cache, atomic locks, rate limiters, queue backing. (One app server?
  You may not need Redis at all.)
- **Don't use it when:** the data must survive independently of the cache
  ([Caching](caching.md): durable state belongs in the database), or you need
  query semantics — Redis is a key-value structure server, not a search engine.
- **The classic production incident:** `maxmemory` reached with the wrong
  eviction policy — the lock keys or session store start evicting under write
  pressure, and "random user logged out" becomes "random user's lock vanished,
  job ran twice". Second classic: treating Redis as durable storage and losing
  workflow state in a failover.

## Problem

Redis changes the failure model in four ways that application code rarely
anticipates:

1. **Eviction is normal operation.** With `maxmemory` set (it must be), keys are
   evicted by policy — including, with the wrong policy, the keys your locks
   depend on.
2. **It is single-threaded per instance.** One slow command (`KEYS *`, a huge
   `SMEMBERS`, a blocking `SAVE`) stalls every client.
3. **Persistence is optional and approximate.** RDB snapshots lose the tail;
   AOF trades latency for durability; replicas are asynchronous — failover can
   lose the last writes ([outbox](../messaging/outbox.md) dedupe rows do NOT
   belong here).
4. **Connections are long-lived** — idle drops, failovers, and `TIMEOUT` config
   interact with worker processes ([queues](../messaging/queues.md)).

None of these are bugs; they're the product's design. The engineering is knowing
which keys may vanish and which may not — then configuring accordingly.

## When to use it

- Shared cache across app servers ([Caching](caching.md)) with tag support and
  real eviction semantics.
- Atomic locks and mutexes ([Locking](locking.md)) — the reference store for
  everything mutex-backed in this handbook.
- Queue backing ([Queues](../messaging/queues.md)) — fast, with Horizon for ops.
- Rate limiting, counters, ephemeral coordination state.

## When NOT to use it

- **Durable records.** Dedupe tables, outbox rows, financial state — these live
  in the database next to the effects they guarantee
  ([Idempotency](../messaging/idempotency.md)).
- **Relational queries.** "All orders for customer X in March" is a database
  query; modeling it in Redis structures couples your access patterns to your
  data layout.
- **Single-server apps that fit in APCu/SQLite** — the ops cost of Redis
  (memory planning, failover, monitoring) needs a reason.
- **As a message bus with delivery guarantees.** Redis streams exist, but
  at-least-once semantics with consumer acks are a design project — the queue
  chapter's guarantees don't transfer automatically
  ([outbox](../messaging/outbox.md) covers the reliable path).

## The client: phpredis or Predis

Verified across Laravel 10–13: the default client is **phpredis** (the C
extension — faster, connection pooling in the extension) and **Predis 2.x** is
the pure-PHP alternative (`REDIS_CLIENT=predis`). Choose phpredis unless the
host can't install extensions; know that the client choice changes available
options (phpredis exposes `prefix`/`serializer`/scan options in
`config/database.php`, Predis takes connection parameters). Sentinel is **not**
supported by Laravel core — it needs a community connector package, so plan it
before the first failover drill, not during.

## The simplest correct implementation

Redis as the cache + lock store, with the memory contract stated explicitly:

<!-- example: redis/lock-semantics -->
```php
<?php

// config/database.php (relevant slice) — one Redis instance, cache-only:
// 'cache' => ['host' => env('REDIS_HOST'), 'password' => ..., 'port' => ...,
//              'database' => 1],   // separate logical DB from queues

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;

final class NightlyReportRunner
{
    public function run(): void
    {
        // Locks ride on the redis cache store: shared across all servers,
        // TTL-bounded, owner-checked release (see Locking chapter).
        $lock = Cache::store('redis')->lock('report:nightly', seconds: 300);

        try {
            $lock->block(5);
            // ... the report ...
        } catch (LockTimeoutException) {
            return; // another server is already running it
        } finally {
            $lock->release();
        }
    }
}
```

That's the whole minimal story — and it is exactly why Redis ops discipline
matters: this lock's correctness depends on the key *staying in memory* for its
TTL. An eviction policy that removes it mid-flight reintroduces the double-execution
the lock exists to prevent ([Locking](locking.md)).

## What production requires

### A memory budget with a matching eviction policy

- **Set `maxmemory`** (defaults to unbounded — Redis will eat the box). Size it
  from measured working set + headroom.
- **Pick the policy by tenant of the data:**
  - Cache-only instance: `allkeys-lru` (or `allkeys-lfu`) — anything may go.
  - Instance mixing locks/sessions with cache: `volatile-ttl`/`volatile-lru`
    evict only keys WITH TTLs — and then *every* key must have a TTL, or it
    accumulates forever.
  - The real answer for mixed criticality: **separate instances** (locks on one,
    bulk cache on another) — eviction on the bulk instance can't touch lock
    keys.
- **Watch `evicted_keys`** — evictions on a lock/session instance are an
  incident, not a metric.

### Durability: decide per instance, then test the failover

- Cache-only: no persistence needed (`save ""`, AOF off) — a cold cache after
  restart is a warmup problem, not data loss
  ([Caching](caching.md) stampede guidance).
- Anything approaching state (counters, coordination): AOF `everysec` is the
  usual floor; remember replicas are async — failover loses the tail. Anything
  that must not be lost belongs in the database, full stop.

### Key hygiene

- **Namespaces by owner** (`billing:`, `locks:`, `cache:`) — grep-ability and
  per-owner TTL audits ([modular monolith](../architecture/modular-monolith.md)).
- **Every key has a TTL unless it provably can't** — the periodic `SCAN` audit
  (never `KEYS *`: it blocks the single thread) finds the accumulators.
- **Prefix discipline:** Laravel's `CACHE_PREFIX`/`REDIS_PREFIX` is your
  namespacing boundary between environments sharing an instance — never share
  an instance between environments without distinct prefixes.

### The harness runs these claims

Tag-based invalidation on the shared store — the group flush that must reach
every server's cache at once:

<!-- example: redis/tag-invalidation -->
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class PricingCache
{
    public function put(int $productId, array $prices): void
    {
        Cache::store('redis')->tags(['pricing'])
            ->put("pricing:{$productId}", $prices, now()->addHours(12));
    }

    public function flushAll(): void
    {
        // One call, every server: tags are why the shared store matters.
        Cache::store('redis')->tags(['pricing'])->flush();
    }
}
```

The ⚙️ tests for this chapter call `redisRequired()` — they **skip** when no
Redis server is reachable (local laptops) and **execute in CI** against the
Redis service container. That's the pattern for any redis-dependent claim in
this handbook: encoded for real, skipped gracefully.

## What usually goes wrong

1. **Evicted lock keys → double execution.** *Root cause:* `maxmemory` reached on
   a mixed instance; `allkeys-*` evicts a live lock. *Detection:* duplicate-execution
   metrics ([Locking](locking.md)) correlating with `evicted_keys` growth. *Fix:*
   separate instances or `volatile-*` + universal TTLs.
2. **Redis as durable store.** *Root cause:* counters/dedupe state in Redis,
   "it persists fine". *Detection:* data that vanishes after failover/restart.
   *Fix:* database of record; Redis only accelerates.
3. **`KEYS` in production.** *Root cause:* debugging convenience. *Detection:*
   latency spikes matching admin sessions. *Fix:* `SCAN` with COUNT; the runbook
   forbids `KEYS` explicitly.
4. **Long-running workers and dropped connections.** *Root cause:* idle timeout
   or failover between worker and Redis; the next command throws. *Detection:*
   intermittent RedisException in worker logs after quiet periods. *Fix:*
   framework reconnect-on-error posture + bounded worker recycling
   (`--max-jobs`/`--max-time`, [Queues](../messaging/queues.md)).
5. **Shared instance without prefix separation.** *Root cause:* staging and prod
   on one Redis. *Detection:* impossible keys, wrong-environment cache hits. *Fix:*
   prefixes per environment — or better, separate instances.
6. **Big keys.** *Root cause:* serialized arrays growing unbounded in one key.
   *Detection:* `--bigkeys` scan; memory plateau per key. *Fix:* hash buckets,
   expiries, size limits at the writer.

## How to test it

- **With Redis (CI / any reachable server):** the ⚙️ tests here call
  `redisRequired()` and assert the real semantics — lock exclusivity and
  owner-checked release on the redis store, tag put/flush behavior
  (`examples/tests/RedisExamplesTest.php`).
- **Without Redis:** the same tests skip — the array store tests in
  [Caching](caching.md) / [Locking](locking.md) cover the *logic*; this chapter's
  tests cover the *store*.
- **Eviction drill (ops, not CI):** on a staging instance, set a tiny `maxmemory`
  and watch which keys vanish under load — it's the fastest way to learn your
  policy's real semantics.

## How to operate it

- **The four numbers:** memory used vs maxmemory, `evicted_keys` rate, hit ratio
  ([Caching](caching.md)), connected clients. Plus `rejected_connections` —
  connection exhaustion is its own outage.
- **Latency budget:** Redis should be sub-millisecond; `latency history` and the
  slowlog find the offenders (usually big keys or blocking commands).
- **Failover drill:** practice the replica promotion before you need it — and
  write down what your app does during the 2-5s of connection errors (it should
  degrade, not 500: caches fail open to the origin with rate limiting).
- **3am runbook:** memory pressure → which keyspace grew (SCAN audit), policy
  correct?; lock double-execution → check `evicted_keys` on the lock instance
  first; worker RedisExceptions → failover or idle drop? reconnect posture.

## How it scales

- **Vertical first** (memory, then CPU — it's single-threaded per instance), then
  **read replicas** for read scale (async — stale-tolerant reads only), then
  **cluster** — which changes your key design (multi-key operations, including
  tags, must co-locate slots; [Caching](caching.md)).
- **Splitting by criticality** (locks vs bulk cache) is the scaling move that
  also fixes the eviction incident class — do it before cluster.
- **Beyond Redis:** when the coordination itself is the bottleneck, the answer
  is usually architectural (fewer shared hot keys, queue-mediated updates), not
  a bigger server.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| phpredis client (default) | ✅ | ✅ | ✅ | ✅ |
| Predis 2.x client | ✅ | ✅ | ✅ | ✅ |
| `Cache::store('redis')` locks + tags | ✅ | ✅ | ✅ | ✅ |
| `cache:prune-stale-tags` | ✅ | ✅ | ✅ | ✅ |
| Sentinel support in core | ❌ community packages | ❌ | ❌ | ❌ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The Redis integration surface is stable across the matrix; the version-relevant
decisions are client choice and topology, both above.

## Related

- [Caching](caching.md) — what gets cached, key design, stampede protection;
  Redis is that chapter's reference store.
- [Locking](locking.md) — atomic locks and their ownership/TTL rules.
- [Queues](../messaging/queues.md) — redis queue driver and worker connection
  lifecycle.
- [Idempotency](../messaging/idempotency.md) — why dedupe guarantees never live
  here.
- Roadmap: Redis persistence internals, cluster topologies.
