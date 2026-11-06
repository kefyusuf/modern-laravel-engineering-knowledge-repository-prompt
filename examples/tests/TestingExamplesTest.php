<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Testing\BrittleJob;

uses()->group('testing');

/**
 * Executed claims for chapters/quality/testing.md.
 */
it('reproduces the check-then-insert race deterministically, then shows the fix', function () {
    /** @example-id testing/race-interleave */
    $insertNaive = function (string $key, bool $sawNothing) {
        if ($sawNothing) {
            DB::table('race_log')->insert(['key' => $key, 'created_at' => now()]);
        }
    };

    // RED: the naive implementation under a hand-sequenced interleave —
    // both actors check before either inserts.
    $aSawNothing = ! DB::table('race_log')->where('key', 'k1')->exists();
    $bSawNothing = ! DB::table('race_log')->where('key', 'k1')->exists();
    $insertNaive('k1', $aSawNothing);
    $insertNaive('k1', $bSawNothing);

    expect(DB::table('race_log')->where('key', 'k1')->count())->toBe(2);

    // GREEN: the same interleave against the unique-index-backed claim —
    // exactly one insert wins (see IdempotencyExamplesTest for the full
    // contract).
    $claimFixed = function (string $key, bool $sawNothing) {
        if (! $sawNothing) {
            return 0;
        }

        return DB::table('processed_messages')->insertOrIgnore([
            'source' => 'race-demo',
            'message_key' => $key,
            'created_at' => now(),
        ]);
    };

    $aSawNothing = ! DB::table('processed_messages')->where('message_key', 'k2')->exists();
    $bSawNothing = ! DB::table('processed_messages')->where('message_key', 'k2')->exists();
    $claimFixed('k2', $aSawNothing);
    $claimFixed('k2', $bSawNothing);

    expect(DB::table('processed_messages')->where('message_key', 'k2')->count())->toBe(1);
});

it('injects failure at the dependency seam and exercises the real failure machinery', function () {
    /** @example-id testing/failure-injection */
    config(['queue.default' => 'database']);

    BrittleJob::dispatch(1);
    expect(DB::table('jobs')->count())->toBe(1);

    // One real worker cycle: the job throws, the worker records the
    // failure, the failed() hook fires — no queue faking.
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(1);
    expect(BrittleJob::$failureNotifications)->toBe(1);
    expect(DB::table('jobs')->count())->toBe(0); // not retried: tries = 1
});

it('freezes time so TTL semantics are instant and deterministic', function () {
    /** @example-id testing/time-freeze */
    Cache::put('session:token', 'abc', now()->addSeconds(60));
    expect(Cache::get('session:token'))->toBe('abc');

    // Before expiry: present.
    Carbon::setTestNow(now()->addSeconds(59));
    expect(Cache::get('session:token'))->toBe('abc');

    // After expiry: gone — no waiting involved.
    Carbon::setTestNow(now()->addSeconds(61));
    expect(Cache::get('session:token'))->toBeNull();
});

beforeEach(function () {
    BrittleJob::$failureNotifications = 0;
});

afterEach(function () {
    Carbon::setTestNow();
    Cache::flush();
});
