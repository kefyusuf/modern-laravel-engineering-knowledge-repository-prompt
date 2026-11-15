<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

uses()->group('api-design');

/**
 * Executed claims for chapters/interfaces/api-design.md.
 */
it('enforces the abuse budget with an exact, visible window', function () {
    /** @example-id api-design/rate-limit-window */

    // Freeze the clock BEFORE the first hit so the window arithmetic is
    // fully deterministic (no wall-clock stalls between assertions).
    Carbon::setTestNow(now()->startOfMinute());

    // RateLimiter::attempt() carries the window semantics directly (its own
    // timer keys) — the throttle middleware's named-limiter registry is a
    // separate HTTP-layer concern this unit test deliberately does not
    // exercise. attempt() returns the callback's result when allowed, and
    // false when the budget is exhausted.
    $attempt = fn () => RateLimiter::attempt(
        key: 'api:consumer:7',
        maxAttempts: 3,
        callback: fn () => 'handled',
    );

    expect($attempt())->toBe('handled');
    expect($attempt())->toBe('handled');
    expect($attempt())->toBe('handled');

    // Budget exhausted: further hits are denied, and the client can see
    // exactly when it may retry.
    expect($attempt())->toBeFalse();
    expect(RateLimiter::tooManyAttempts('api:consumer:7', 3))->toBeTrue();
    $retryAfter = RateLimiter::availableIn('api:consumer:7');
    expect($retryAfter)->toBeGreaterThan(0);
    expect($retryAfter)->toBeLessThanOrEqual(60);

    // Frozen clock: within the same window the denial persists...
    Carbon::setTestNow(now()->addSeconds(10));
    expect(RateLimiter::tooManyAttempts('api:consumer:7', 3))->toBeTrue();

    // ...and after the decay the budget is restored, no restart needed.
    Carbon::setTestNow(now()->addSeconds(55));
    expect(RateLimiter::tooManyAttempts('api:consumer:7', 3))->toBeFalse();
    expect($attempt())->toBe('handled');
});

afterEach(function () {
    Carbon::setTestNow();
    RateLimiter::clear('api:consumer:7');
});
