<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

uses()->group('api-design');

/**
 * Executed claims for chapters/interfaces/api-design.md.
 */
it('enforces the abuse budget with an exact, visible window', function () {
    /** @example-id api/rate-limit-window */
    RateLimiter::for('api', function () {
        return \Illuminate\Cache\RateLimiting\Limit::perMinute(3)->by('consumer:7');
    });

    // The named limiter is executed per hit, exactly like the throttle
    // middleware runs it for a request. attempt() returns the CALLBACK's
    // result when allowed, and false when the budget is exhausted.
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

beforeEach(function () {
    RateLimiter::clear('api:consumer:7');
});

afterEach(function () {
    Carbon::setTestNow();
    RateLimiter::clear('api:consumer:7');
});
