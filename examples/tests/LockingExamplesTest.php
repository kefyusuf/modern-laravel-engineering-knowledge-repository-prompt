<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Locking;

uses()->group('locking');

/**
 * Executed claims for chapters/data/locking.md.
 *
 * Cross-connection row-lock blocking is a database-engine behavior (SQLite
 * cannot demonstrate concurrent connections against :memory:), so the
 * harness verifies: the pattern's SQL executes, the unique-index backstop
 * enforces the invariant, and the cache-lock scope/ownership/TTL contract.
 */
it('protects read-modify-write with a row lock and a unique-index backstop', function () {
    /** @example-id locking/db-lock-for-update */
    DB::table('inventories')->insert(['id' => 1, 'available' => 5]);

    $fixture = new Locking;
    $orderId = $fixture->purchase(1, 2, 100);

    expect($orderId)->toBeInt();
    expect(DB::table('inventories')->find(1)->available)->toBe(3);

    // The backstop: a second active purchase for the same customer+sku
    // violates the unique index, and the whole transaction rolls back —
    // inventory is restored by the rollback, not by application code.
    // (Catch the specific failure: a bare Throwable would swallow the
    // test's own fail() if the constraint were somehow missing.)
    try {
        $fixture->purchase(1, 2, 100);
        $this->fail('expected unique constraint violation');
    } catch (\Illuminate\Database\QueryException) {
        expect(DB::table('inventories')->find(1)->available)->toBe(3);
        expect(DB::table('orders')->where('customer_id', 100)->count())->toBe(1);
    }
});

it('grants cache locks exclusively, honors ownership, and expires on TTL', function () {
    /** @example-id locking/cache-lock-owner */
    $fixture = new Locking;

    // Same resource: second holder is excluded while the lock lives.
    $lock = Cache::lock('report:publish:42', 30);
    expect($lock->acquire())->toBeTrue();

    $held = Cache::lock('report:publish:42', 30);
    expect($held->acquire())->toBeFalse();

    // Owner-guarded release frees it for the next worker.
    $lock->release();
    expect($held->acquire())->toBeTrue();
    $held->release();

    // TTL expiry: after the lock dies, a new holder is admitted.
    $short = Cache::lock('report:publish:42', 1);
    expect($short->acquire())->toBeTrue();
    Carbon::setTestNow(now()->addSeconds(2));
    $late = Cache::lock('report:publish:42', 30);
    expect($late->acquire())->toBeTrue();
    $late->release();
});

it('serializes workers on the same mutex key and isolates different keys', function () {
    /** @example-id locking/without-overlapping */
    $fixture = new Locking;

    // Same resource key: exclusive (this is what WithoutOverlapping's
    // mutex does for jobs sharing a key).
    [$sameFirst, $sameSecond] = $fixture->scopeContract('search-index:rebuild', 'search-index:rebuild');
    expect($sameFirst)->toBeTrue();
    expect($sameSecond)->toBeFalse();

    // Different resources: independent locks, both proceed.
    [$a, $b] = $fixture->scopeContract('search-index:rebuild', 'search-index:rebuild:2');
    expect($a)->toBeTrue();
    expect($b)->toBeTrue();
});

it('derives lock scope from the resource, not the caller', function () {
    /** @example-id locking/lock-scope-mismatch */
    $fixture = new Locking;

    // Resource-derived keys (tenant+customer), as the production-safe
    // example prescribes:
    $keyFor = fn (int $tenant, int $customer) => "balance:sync:{$tenant}:{$customer}";

    // Two different resources never collide...
    [$a, $b] = $fixture->scopeContract($keyFor(1, 10), $keyFor(1, 20));
    expect($a)->toBeTrue();
    expect($b)->toBeTrue();

    // ...and the same resource is protected across every caller.
    [$first, $second] = $fixture->scopeContract($keyFor(1, 10), $keyFor(1, 10));
    expect($first)->toBeTrue();
    expect($second)->toBeFalse();
});

afterEach(function () {
    Carbon::setTestNow();
    Cache::flush();
});
