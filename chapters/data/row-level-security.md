---
title: Row-level security
topic: row-level-security
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/RowLevelSecurityExamplesTest.php
---

# Row-level security

Row-level security (RLS) is tenant isolation enforced at the **row** — every
read and every write filtered by "who is asking, and which tenant do they
belong to". It exists because the [authorization](../interfaces/authorization.md)
chapter's policy checks guard *decisions*, while isolation needs a guarantee on
*every query* — including the one a new hire writes at 6pm on Friday. This
chapter covers both layers: application-level scopes (executable, testable,
always on) and database-level RLS (PostgreSQL's native enforcement, the second
line of defense).

## TL;DR

- **Use it when:** the system is multi-tenant and "tenant A must never see
  tenant B's rows" is a security requirement — not a filter preference.
- **Don't do it:** as the *only* line. Application scopes cover application
  code; a raw `DB::statement`, a queued job reusing the wrong connection, or a
  forgotten `where` still slips past — defense in depth means the database
  enforces too (PostgreSQL RLS).
- **The classic production incident:** the admin panel's "all tenants" query
  accidentally bound to the customer-facing repo — or the new endpoint that
  forgets the `where('tenant_id')`. The [caching](caching.md) cross-tenant key
  incident, the [authorization](../interfaces/authorization.md) scope miss, and
  this one are the same requirement at three layers.

## Problem

Tenant isolation by discipline ("always remember the where clause") fails at
scale for a boring reason: the number of queries grows faster than the number
of people remembering. One missed `where('tenant_id', ...)` in any read, any
report, any relationship query leaks data. The fix is to make the filter
*structural*: applied by the framework on every query for the model (global
scopes), and — for the second line — enforced by the database itself
(PostgreSQL RLS policies), so even raw SQL is bounded.

## When to use it

- Multi-tenant products where cross-tenant visibility is a breach
  (SaaS apps, agency tools, anything with `tenant_id`).
- Systems where compliance makes isolation auditable ("show me that no query
  could cross tenants").

## When NOT to use it

- **Single-tenant deployments.** One customer per deployment: isolation is
  infrastructure-level; row-level machinery is overhead.
- **As a substitute for [authorization](../interfaces/authorization.md).**
  RLS answers "which tenant's rows?"; policies answer "may this user do
  this?". Different questions, different layers — you need both.
- **Global scopes on cross-tenant admin tooling.** The support panel
  legitimately queries across tenants — scope-exempt tooling models
  (`withoutGlobalScopes`) exist but must be explicit, few, and reviewed.

## The simplest correct implementation

The application-level tenant scope — the filter applied by the model itself:

<!-- example: row-level-security/global-tenant-scope -->
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    /**
     * The tenant context — set once per request/job by the boundary
     * middleware ([observability](../quality/observability.md) Context
     * discipline), read by the scope. Plain and explicit on purpose.
     */
    public static ?int $currentTenantId = null;

    protected static function booted(): void
    {
        // Applied to EVERY query for this model — reads, aggregates,
        // relations. Forgetting a where clause is now impossible.
        static::addGlobalScope('tenant', function (Builder $query) {
            if (static::$currentTenantId !== null) {
                $query->where('tenant_id', static::$currentTenantId);
            }
        });
    }
}
```

```php
<?php

// The boundary sets the context once; the model enforces it everywhere:
Order::$currentTenantId = $request->tenantId;
```

With this on, "all orders" *is* "my tenant's orders" — for every read in the
application, forever.

## What production requires

### The scope must be on, deliberately, everywhere

- **Context set at every entry point** — HTTP middleware, console kernel,
  queue job boundaries ([observability](../quality/observability.md)'s
  context-propagation pattern, reused for security). An entry point that
  forgets leaves the scope inert (`null` → no filter) — which is why the
  context setter is a single service, not a static call scattered around.
- **Writes included:** global scopes filter reads; creates must be checked
  too (the model's `creating` event asserting `tenant_id` matches the
  context — or a [policy](../interfaces/authorization.md) doing it).
- **The escape hatch is loud:** `withoutGlobalScopes()` is a reviewed,
  grep-counted statement (admin tooling only) — the
  [testing](../quality/testing.md) chapter's grep-gate pattern applies.

### The database line: PostgreSQL RLS

Application scopes bound *application* queries. PostgreSQL's RLS binds the
**database session** — even raw SQL, even a forgotten where:

```sql
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation ON orders
    USING (tenant_id = current_setting('app.current_tenant')::int);
```

The application sets the tenant per session/transaction
(`SET app.current_tenant` / `set_config(..., true)` in the transaction's
opening — one statement, per connection checkout), and PostgreSQL refuses
cross-tenant rows even to `SELECT *`. Requirements:

- **Superusers bypass RLS by default** (`FORCE ROW LEVEL SECURITY` for table
  owners) — know which roles are exempt.
- **The session variable must be set** — an unset setting makes the policy
  deny (or pass, per policy design — choose fail-closed).
- **Migrations/seeds run as an exempt role** — the exemption list is small
  and audited.

### The second-line posture

| Layer | Enforces | Covers |
|---|---|---|
| Application global scope | Every Eloquent query | App code, relations, aggregates |
| Policy checks | Every decision | Actions, not rows |
| PostgreSQL RLS | Every statement on the table | Raw SQL, jobs, future code |

## What usually goes wrong

1. **The forgotten `where` in a new endpoint.** *Root cause:* isolation by
   discipline. *Detection:* the cross-tenant test (⚙️) fails — or a customer
   reports the leak. *Fix:* the global scope makes it structural.
2. **The inert context.** *Root cause:* an entry point (a new job class, an
   artisan command) never sets the tenant context — the scope sees `null`
   and filters nothing. *Detection:* unfiltered reads in query telemetry
   ([observability](../quality/observability.md)). *Fix:* one context
   service, set at every boundary; a fail-closed assertion in the scope
   (null context → throw in production).
3. **withoutGlobalScopes sprawl.** *Root cause:* admin tooling, reports, and
   one-off fixes reaching for the escape hatch. *Detection:* grep count
   grows. *Fix:* an explicit, reviewed allow-list; a dedicated
   cross-tenant admin model with its own policies.
4. **Superuser bypass forgotten.** *Root cause:* PostgreSQL RLS doesn't bind
   table owners/superusers by default — the app connects as a role that
   bypasses everything. *Detection:* RLS "enabled" but leaks persist.
   *Fix:* `FORCE ROW LEVEL SECURITY`; the app connects with a non-exempt
   role.
5. **Writes without scope.** *Root cause:* scopes filter reads; `create()`
   with the wrong `tenant_id` writes cross-tenant. *Detection:* rows with
   foreign tenant ids. *Fix:* creating-event guard + policies
   ([authorization](../interfaces/authorization.md)).
6. **The queue job with the wrong context.** *Root cause:* a job re-running
   under a stale or absent tenant context. *Detection:* cross-tenant rows
   touched from workers. *Fix:* context in the payload/context facade
   ([observability](../quality/observability.md)), set at job start.

## How to test it

- **The scope filters reads (⚙️
  `examples/tests/RowLevelSecurityExamplesTest.php`):** seed two tenants'
  rows, set the context, assert every read returns only the context's rows —
  the isolation claim, executed.
- **The escape hatch is explicit:** `withoutGlobalScopes()` returns both
  tenants' rows — asserted, so the hatch is a documented behavior, not an
  accident.
- **Write-path guard:** a create with a mismatched tenant is denied (or
  corrected) — the creating-event/policy claim.
- **Database RLS is verified on PostgreSQL in staging** — the harness runs
  SQLite (no RLS); the policy SQL above is exercised in the staging drill
  ([deployment](../runtime/deployment.md)).

## How to operate it

- **Tenant-context telemetry:** every request/job logs which tenant context
  it carried — the unfiltered-read alarm keys on its absence
  ([observability](../quality/observability.md)).
- **The escape-hatch grep in CI:** `withoutGlobalScopes` count as a
  code-health metric — growing is a finding.
- **3am runbook:** cross-tenant report → which query, which layer missed
  (scope? policy? database RLS?) — three layers, three runbook entries, one
  requirement.

## How it scales

- **Global scopes are per-model** — the discipline scales with the model
  count; a trait shared by tenant-scoped models keeps the definition single.
- **PostgreSQL RLS scales to every statement** — the policy is the table's,
  not the code's; new code inherits it automatically.
- **The ceiling:** sharding by tenant moves the isolation line into
  infrastructure — a bigger hammer for a problem the scopes should have
  already solved correctly.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Global scopes / `booted()` / `withoutGlobalScopes()` | ✅ | ✅ | ✅ | ✅ |
| Model `creating` events | ✅ | ✅ | ✅ | ✅ |
| PostgreSQL RLS (engine capability) | ✅ (9.5+) | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The scoping mechanics are stable across the matrix; the database layer is an
engine capability (PostgreSQL 9.5+ RLS), independent of Laravel versions.

## Related

- [Authorization](../interfaces/authorization.md) — the decision layer RLS
  complements.
- [Caching](caching.md) / [Redis](redis.md) — the tenant-scope line in keys
  and stores.
- [Observability](../quality/observability.md) — the tenant context that
  both layers consume.
- [Security](../interfaces/security.md) — the breach classes isolation
  closes.
- [The modular monolith](../architecture/modular-monolith.md) — module-owned
  scoping models.
- Roadmap: RLS on managed databases, tenant sharding strategies.
