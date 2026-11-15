<?php

use Illuminate\Http\Request;
use MLE\Examples\Tests\Fixtures\ContentSecurityPolicy;

uses()->group('content-security-policy');

/**
 * Executed claims for chapters/interfaces/content-security-policy.md.
 */
it('carries the exact policy contract on every rendered response', function () {
    /** @example-id content-security-policy/header-contract */

    $response = (new ContentSecurityPolicy)->handle(
        Request::create('/dashboard', 'GET'),
        fn ($request) => response('<html><body>ok</body></html>'),
    );

    $policy = $response->headers->get('Content-Security-Policy');

    // The contract, directive by directive — deny-by-default base plus
    // narrow grants (MDN's directive list is the review checklist).
    expect($policy)->toContain("default-src 'self'");
    expect($policy)->toContain("script-src 'self'");
    expect($policy)->toContain("object-src 'none'");
    expect($policy)->toContain("base-uri 'none'");
    expect($policy)->toContain("frame-ancestors 'none'");
    expect($policy)->toContain("form-action 'self'");

    // Error-shaped responses carry the header too — the error page is a
    // rendering surface just like any other.
    $errorResponse = (new ContentSecurityPolicy)->handle(
        Request::create('/missing', 'GET'),
        fn ($request) => response('<html>not found</html>', 404),
    );
    expect($errorResponse->headers->get('Content-Security-Policy'))->toBe($policy);
});
