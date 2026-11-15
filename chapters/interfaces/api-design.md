---
title: API design
topic: api-design
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/ApiDesignExamplesTest.php
---

# API design

An HTTP API is a contract with strangers who cannot read your changelog: client
integrations, partners, your own frontend. The design work is deciding — and
then defending — four contracts: **resource shape, error shape, versioning
posture, and abuse budget** (rate limits). This chapter covers them in the order
clients feel them, with the Laravel mechanics that keep each contract honest.

## TL;DR

- **Use it (this discipline) when:** anything outside your deploy unit consumes
  your HTTP API — including your own SPA, which is a client that ships on a
  different schedule.
- **Don't do it:** by exposing Eloquent models verbatim — the database schema
  is not a public contract, and every column rename becomes a breaking release.
- **The classic production incident:** a mobile client built against
  undocumented response shapes breaks at the next deploy because a serializer
  "harmlessly" added a field, or a 500 with an HTML error page replaces the
  JSON error contract the client parses. Second classic: no rate limit, and a
  partner's retry loop becomes an outage.

## Problem

APIs fail through drift, not through initial design. The first version is
usually sensible; the decay happens when:

1. **Internal models leak into responses** — `Model::toArray()` ships the
   schema; renames and internal fields become public API surface.
2. **Errors are inconsistent** — some endpoints return `{error: ...}`, some
   `{message: ...}`, some HTML; clients write three parsers and parse none
   well.
3. **Versioning was never decided** — the first breaking change either breaks
   all clients or forks the codebase overnight.
4. **Abuse budgets don't exist** — no rate limits, no idempotency story; the
   first misbehaving client is your outage.

Each contract below is a decision to make once and enforce mechanically.

## When to use it

- Any HTTP surface consumed by another deploy unit (mobile, SPA, partners).
- Public/partner APIs — where every rule here is mandatory, not advisory.

## When NOT to use it

- **Server-rendered apps calling themselves** — internal endpoints behind
  same-origin forms have different contracts (CSRF, redirects) — don't import
  API-versioning ceremony into them.
- **Premature public APIs.** "We might expose this later" is not a reason to
  freeze a contract now — freeze when a client exists.
- **GraphQL/REST holy wars.** The resource discipline below applies to both;
  pick per team competence and client needs.

## The simplest correct implementation

The resource contract — an explicit shape, never the model:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    /**
     * THE contract: explicit fields, stable names, typed values. New
     * fields are additive (safe); renames and removals are breaking
     * (versioned). Nothing else from the model leaks.
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total_cents' => $this->total_cents,
            'placed_at' => $this->placed_at?->toIso8601String(),
        ];
    }
}
```

```php
<?php

// The endpoint: thin adapter (Boundaries chapter), explicit pagination —
// keyset by default (Database performance chapter), envelope documented.
public function index(Request $request)
{
    $orders = Order::query()
        ->where('merchant_id', $request->user()->merchant_id)
        ->orderByDesc('id')
        ->cursorPaginate(20);

    return OrderResource::collection($orders);
}
```

## What production requires

### One error contract, everywhere

Clients parse errors once. Define the shape centrally and make it
inviolable:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "fields": {"email": ["The email must be a valid address."]}
  }
}
```

Laravel's exception handler renders validation errors and exceptions for the
whole app — one place to shape them (`bootstrap/app.php`'s `withExceptions` on
Laravel 11+, `App\Exceptions\Handler::render()` on Laravel 10). Non-JSON
requests get HTML error pages by default — force JSON rendering for API
routes (`Accept: application/json` handling + `shouldRenderJsonWhen`), or the
first 500 becomes an HTML document a JSON client cannot parse.

### Versioning posture (decide before you need it)

- **URL path versioning** (`/api/v1/...`) — blunt but visible and cacheable;
  the default choice.
- **The posture:** additive changes are **not** breaking (new optional
  request fields, new response fields); renames/removals/semantic changes
  **are** — they land in `/v2/`, never silently in `/v1/`.
- **Deprecation is communication:** `Deprecation`/`Sunset` headers + a
  documented sunset window per contract — clients can only respect what you
  announce.

### Abuse budgets: rate limiting with a window the client can see

<!-- example: api-design/rate-limit-window -->
```php
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Budget per consumer, not per IP alone (partners NAT).
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
```

```php
<?php

// The middleware returns 429 with Retry-After — and the response is part
// of the contract, so the client's backoff can be exact:
//   Retry-After: <seconds>
//   X-RateLimit-Limit / -Remaining / -Reset   (the budget, visible)
```

Rules that keep the budget honest:

- **Key by consumer identity**, with IP as the anonymous fallback — a key of
  raw IP punishes everyone behind the same NAT; a key of user id without IP
  fallback lets anonymous abuse run free.
- **The 429 response is documentation**: `Retry-After` (exact seconds) plus
  the `X-RateLimit-*` headers let well-behaved clients self-regulate.
- **Retries are the client's right** — which makes the write endpoints
  [idempotency-key](../messaging/idempotency.md) territory: a client that
  backs off and retries must not double-charge.

### The other contracts, briefly

- **Pagination:** keyset by default
  ([database performance](../data/database-performance.md)); the envelope
  (`data`, `next_cursor`) is part of the contract and never changes silently.
- **Idempotency:** `Idempotency-Key` on POST writes
  ([idempotency](../messaging/idempotency.md) middleware) — retries are a
  fact of networked life.
- **Auth:** token-based (Sanctum-class tooling) over session cookies for
  third-party clients; scopes if tokens carry permissions. The authorization
  rules themselves live in [authorization](authorization.md) — the API layer
  only enforces what the policies decide.
- **Consistency:** long-running operations return `202` + a status URL, never
  a slow request ([queues](../messaging/queues.md) async UX).

## What usually goes wrong

1. **Schema leakage.** *Root cause:* `Model::toArray()`-shaped responses.
   *Detection:* a migration renaming a column "breaks the API" — it IS the
   API. *Fix:* explicit resources; the model is not the contract.
2. **HTML 500s in a JSON API.** *Root cause:* exception rendering not forced
   to JSON for API routes. *Detection:* clients logging "unexpected token <".
   *Fix:* `shouldRenderJsonWhen` / per-middleware exception shaping; contract
   test that any error is parseable JSON.
3. **Silent breaking changes.** *Root cause:* no distinction between additive
   and breaking edits. *Detection:* partner integrations breaking on routine
   deploys. *Fix:* the versioning posture + contract tests
   ([testing](../quality/testing.md)) that pin the response shape.
4. **No abuse budget.** *Root cause:* rate limiting "later". *Detection:*
   latency spikes correlated with one client's cron; database CPU from
   pagination abuse. *Fix:* named limiters keyed by consumer, 429s with
   `Retry-After`.
5. **Retries that double-charge.** *Root cause:* POST endpoints without
   idempotency keys + clients that retry on timeout. *Detection:* duplicate
   orders/payments with the same client payload. *Fix:* the idempotency
   contract on all money-moving endpoints.
6. **Cursor pagination by query string guesswork.** *Root cause:* ad-hoc
   `?page=` handling instead of the framework's cursors. *Detection:* deep
   pages timing out; inconsistent windows. *Fix:* `cursorPaginate` and its
   envelope everywhere.

## How to test it

- **Contract tests pin the shape:** hit the endpoint, assert the exact JSON
  structure (keys present, no extra keys on the core fields) — shape drift
  fails CI before it fails a client. Version tables of a different kind: the
  response schema is a version table.
- **Error-contract tests:** force validation failure, auth failure, and 500 —
  assert the same error envelope on all three.
- **Rate-limit window (⚙️ `examples/tests/ApiDesignExamplesTest.php`):** the
  limiter's window semantics are executable — hits exhaust the budget, denial
  persists until the decay, and the window resets without a restart (frozen
  time — [testing](../quality/testing.md)). The 429 response itself is the
  HTTP adapter's job: exercise it in your app's feature tests.
- **Pagination stability:** covered by the keyset claims in
  [database performance](../data/database-performance.md) — reuse, don't
  duplicate.

## How to operate it

- **The API dashboard:** per-endpoint request rate, error-rate by class
  (4xx vs 5xx separately — 5xx is your fault, 429 is the client's education),
  rate-limit rejections, p95 latency per route.
- **Client behavior telemetry:** top abusers by request volume and by
  429 rate — the conversation with a misbehaving client starts from data.
- **Deprecation tracking:** every versioned route has a sunset date — the
  deprecation is announced (headers + changelog) and usage per version is
  watched from your telemetry until it reaches zero.
- **3am runbook:** 5xx spike → which endpoint, which exception class
  ([observability](../quality/observability.md) request ids); partner outage
  report → their 429 rate (their backoff is broken?) or your error rate — the
  error contract makes both legible.

## How it scales

- **Additive evolution scales forever**; breaking changes scale as versions —
  the posture decides whether v2 is a route group or a rewrite.
- **Rate limiting scales to tiers** (per-consumer budgets, premium limits) —
  the named-limiter mechanism extends without client-visible redesign.
- **When the API becomes the product**, the contract grows governance:
  changelogs, contract tests per released version, deprecation calendars —
  the discipline above is its foundation, not its replacement.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| API resources / `cursorPaginate` / `RateLimiter` | ✅ | ✅ | ✅ | ✅ |
| Exception rendering via `bootstrap/app.php` (`withExceptions`) | Handler class | ✅ slim | ✅ | ✅ |
| `shouldRenderJsonWhen` | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The API surface is stable; the main fork is where exception rendering is
configured (Handler class on 10, `bootstrap/app.php` on 11+).

## Related

- [Authorization](authorization.md) — what a client may do; the API layer only
  enforces policies.
- [Idempotency](../messaging/idempotency.md) — the retry contract for write
  endpoints.
- [Database performance](../data/database-performance.md) — keyset pagination
  and its stability claims.
- [Observability](../quality/observability.md) — request correlation and the
  API dashboard's sources.
- [Boundaries](../architecture/boundaries.md) — the controller-as-adapter rule
  the endpoints follow.
- Roadmap: HATEOAS trade-offs, public API governance.
