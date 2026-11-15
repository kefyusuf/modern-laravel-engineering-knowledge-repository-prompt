<?php

namespace MLE\Examples\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

/**
 * content-security-policy/header-contract — the CSP middleware: the policy
 * string is the reviewed contract; every rendered response carries it.
 */
final class ContentSecurityPolicy
{
    private const POLICY = "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::POLICY);

        return $response;
    }
}
