<?php

namespace MLE\Examples\Tests;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Base for every harness test.
 *
 * Defaults: SQLite in-memory + array cache + sync queue. Tests that need Redis
 * call redisRequired() and auto-skip when no server is reachable so the suite
 * degrades gracefully on laptops (CI always provides Redis).
 */
abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Testbench renames the default connection to "testing" — the failed-jobs
     * provider must use the SAME connection as everything else, or it opens
     * its own empty :memory: database (a real cross-connection trap, which is
     * why the queues chapter tells you to check queue.failed.database).
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.failed.database', $app['config']->get('database.default'));

        // Redis client: phpredis when the extension exists (CI), predis
        // otherwise (this Docker harness). Host follows the container env.
        $app['config']->set('database.redis.client', class_exists(\Redis::class) ? 'phpredis' : 'predis');
        $app['config']->set('database.redis.host', getenv('REDIS_HOST') ?: '127.0.0.1');
        $app['config']->set('database.redis.port', (int) (getenv('REDIS_PORT') ?: 6379));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }

    protected function redisRequired(): void
    {
        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not reachable; lock/redis-specific claims are verified in CI.');
        }
    }
}
