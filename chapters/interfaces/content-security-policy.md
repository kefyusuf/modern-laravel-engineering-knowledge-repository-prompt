---
title: Content-Security-Policy
topic: content-security-policy
status: draft
reviewed-against: "MDN CSP docs & Laravel 12.x, 2026-11"
testbed: examples/tests/ContentSecurityPolicyExamplesTest.php
---

# Content-Security-Policy

A Content-Security-Policy header is the browser-side containment line against
XSS: even when an injection slips through an escape hatch
([security](security.md)), a strict CSP stops the injected script from
loading, running, or phoning home. Laravel has no first-class CSP — you ship
it as middleware with an explicit policy. This chapter is that middleware and
the rollout discipline that keeps a strict policy from breaking the frontend.

## TL;DR

- **Use it when:** the app renders HTML for users — the CSP is the last line
  between an XSS payload and an exfiltrated session.
- **Don't do it:** by adding `'unsafe-inline'` everywhere to "make it work" —
  a CSP that permits inline scripts permits the attack it exists to stop.
- **The classic production incident:** CSP rolled out as `default-src 'self'`
  without a rollout plan — the Vite-injected inline scripts vanish, the site
  breaks, and the header is removed "temporarily". Forever.

## Problem

An XSS payload's power comes from the browser executing attacker-controlled
content: an injected `<script>`, an `eval()`, an inline event handler, a
form posting to an attacker's site. A CSP declares to the browser — per
directive — where scripts, styles, connections, and frames may come from,
and the browser *enforces* it regardless of what the HTML contains:

- `script-src 'self' 'nonce-...'` — only scripts with the page's nonce run;
  injected inline scripts are dead on arrival.
- `object-src 'none'; base-uri 'none'` — plugin objects and base-tag
  hijacking closed.
- `form-action 'self'` — phishing forms can't post outward.

The hard part is rarely the directives — it's that modern Laravel frontends
(Vite builds) inject scripts *and* inline state, so a strict policy needs
nonces threaded through the rendering path, and it needs a **rollout** that
doesn't ship the breakage.

## When to use it

- Any server-rendered HTML surface (Blade) — the target of stored/reflected
  XSS.
- In `Content-Security-Policy-Report-Only` mode from day one — violations are
  reported, nothing breaks, and the policy converges to real.

## When NOT to use it

- **Pure JSON APIs** — no HTML rendering, no CSP surface (the security
  chapter's other disciplines apply).
- **As the XSS fix.** The CSP contains; [security](security.md)'s escaping
  prevents. A CSP never replaces output escaping — defense in depth, with the
  CSP as depth.

## The simplest correct implementation

The middleware — the policy is data, the header is the contract:

<!-- example: content-security-policy/header-contract -->
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ContentSecurityPolicy
{
    /**
     * The policy is a deliberate string, reviewed like a contract:
     * - default-src 'self'      : everything not named falls back to self
     * - script-src 'self'       : scripts from our origin only (Vite's
     *                             external build output) — inline scripts
     *                             need a nonce (rollout below)
     * - object-src 'none'       : no plugin content
     * - base-uri 'none'         : no <base> hijacking
     * - frame-ancestors 'none'  : no clickjacking framing
     * - form-action 'self'      : forms post to us, not outward
     */
    private const POLICY = "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::POLICY);

        return $response;
    }
}
```

```php
<?php

// Registered on the web middleware group — every rendered page carries it.
```

## What production requires

### The rollout: report-only, then enforce

1. **`Content-Security-Policy-Report-Only`** with a `report-to`/`report-uri`
   endpoint: the browser reports violations without blocking — for a
   release cycle, you learn exactly what the real policy must permit.
2. **Fix the violations:** move inline handlers into Vite-bundled scripts,
   add nonces for the scripts that must stay inline
   (`script-src 'self' 'nonce-{...}'` — a per-request nonce generated in
   middleware and threaded into Blade), pin third-party origins precisely.
3. **Flip to enforcing** on the header name only — same policy string, the
   browser now blocks instead of reporting. Violations telemetry stays on.

### Nonces for the scripts that must remain inline

The middleware generates a per-request nonce, shares it with Blade
(`View::share` or the request's attributes), and the policy grows
`'nonce-<value>'`. Vite's `<script>` tags need the nonce attribute — the
community-standard [spatie/laravel-csp](https://github.com/spatie/laravel-csp)
package automates the Vite integration; rolling it by hand (above) is ~40
lines and zero dependencies — both are legitimate; pick per team.

### Telemetry

- **The violation endpoint** (report-only AND enforcing) is where CSP bugs
  surface — count reports per directive; a spike after a deploy is the
  frontend change that needs the policy update
  ([observability](../quality/observability.md) correlation applies).

## What usually goes wrong

1. **`unsafe-inline` as the resolution.** *Root cause:* the strict policy
   broke the frontend and the escape was faster than the fix. *Detection:*
   the header diff — `'unsafe-inline'` in script-src is the attack's allow
   list. *Fix:* nonces for inline scripts; the report-only cycle before
   enforcing.
2. **Report-only forever.** *Root cause:* violations keep arriving, enforcing
   keeps slipping. *Detection:* the header still says `Report-Only` after
   months. *Fix:* a deadline per directive; the violations telemetry is the
   work queue.
3. **The forgotten directive.** *Root cause:* `script-src` tightened but
   `object-src`/`base-uri` left permissive — the injection moves to a less-
   guarded vector. *Detection:* policy review against the MDN directive
   list. *Fix:* deny-by-default (`default-src 'self'`) + explicit narrow
   grants.
4. **CSP breaking third-party scripts silently.** *Root cause:* an analytics
   or chat widget loads from an unpinned origin. *Detection:* violation
   reports; broken widgets in production. *Fix:* pin the exact origins in
   the policy (and review *why* the widget loads what it loads).
5. **Header missing on error pages.** *Root cause:* the middleware skipped on
   error responses. *Detection:* the error page is the injection target.
   *Fix:* set the header on every response — errors included.

## How to test it

- **The header contract (⚙️
  `examples/tests/ContentSecurityPolicyExamplesTest.php`):** run a request
  through the middleware, assert the exact policy string — the contract is
  a header value; drift fails CI before it breaks a frontend.
- **Report-only mode:** assert the `Report-Only` header variant sets the
  same policy without enforcement semantics.
- **Nonce flow:** (when you adopt nonces) assert the middleware generated a
  nonce, the policy contains it, and the Blade view received the same value.
- The enforcement semantics live in the browser — verified via report
  telemetry in production, not via unit tests.

## How to operate it

- **Violation reports are the CSP's dashboard:** per-directive counts, spikes
  correlated to deploys — the policy evolves from evidence
  ([observability](../quality/observability.md)).
- **Policy diffs are security diffs:** a header change is reviewed with the
  same weight as an auth change.
- **3am runbook:** "the site's scripts stopped running" → the enforcing CSP
  met an unlisted origin → check violation reports → pin the origin (or fix
  the frontend) — never remove the header.

## How it scales

- **Per-route policies** (a page embedding a specific widget gets its own
  policy) — the middleware grows a policy registry; the contract-test
  pattern scales with it.
- **Micro-frontends and third-party-heavy pages** are where CSPs get complex
  — keep the deny-by-default base and carve narrow exceptions per page.
- **The ceiling:** CSP is containment, not prevention — the
  [security](security.md) disciplines remain the front line.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Middleware registration surface | `Http/Kernel` | `bootstrap/app.php` | ✅ slim | ✅ slim |
| Response header APIs (`headers->set`) | ✅ | ✅ | ✅ | ✅ |
| Vite integration (build output shape) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

CSP is a browser standard executed on headers Laravel sets — no version fork
beyond the middleware registration surface.

## Related

- [Security](security.md) — the prevention disciplines the CSP backs up.
- [Observability](../quality/observability.md) — violation-report telemetry.
- [API design](api-design.md) — JSON surfaces don't need CSP; their error
  contract lives there.
- [Deployment](../runtime/deployment.md) — the header ships with the
  release; rollout discipline is release discipline.
- Roadmap: trusted-types deep dive, per-route policy registries.
