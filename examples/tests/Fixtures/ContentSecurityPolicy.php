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
    /**
     * The policy is a deliberate string, reviewed like a contract — and
     * asserted EXACTLY by the harness test (a policy that silently grew
     * 'unsafe-inline' must fail CI, which toContain assertions would miss):
     * - default-src 'self'      : everything not named falls back to self
     * - script-src 'self'       : scripts from our origin only (Vite's
     *                             external build output) — inline scripts
     *                             need a nonce (see the CSP chapter's
     *                             rollout section)
     * - object-src 'none'       : no plugin content
     * - base-uri 'none'         : no <base> hijacking
     * - frame-ancestors 'none'  : no clickjacking framing
     * - form-action 'self'      : forms post to us, not outward
     */
    public const POLICY = "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::POLICY);

        return $response;
    }
}
