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
