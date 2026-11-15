---
title: Authorization
topic: authorization
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/AuthorizationExamplesTest.php
---

# Authorization

Authorization answers "may THIS actor perform THIS action on THIS resource?" —
a question your system must answer from **one place per rule**, not from
scattered `if` statements. Laravel's two mechanisms (gates for abilities,
policies for resource rules) are the home; the discipline is what this chapter
adds: decide once, enforce everywhere, include the tenant scope in the rule,
and test the denials as seriously as the grants.

## TL;DR

- **Use it when:** any state-changing action has more than zero rules about who
  may perform it — which is: always, once a resource has owners or tenants.
- **Don't do it:** in controllers, route closures, or the client. An
  authorization check that lives where the request arrives is a check that the
  next entry point (console, queue, API) silently lacks.
- **The classic production incident:** IDOR — "the ownership check works" for
  the UI flow, but the API endpoint for the same resource forgets it; user 10
  requests `/orders/42` belonging to user 11 and gets the data. Second
  classic, multi-tenant: the ownership rule matches but the **tenant scope**
  was never part of it.

## Problem

Authorization failures are rarely about missing frameworks — Laravel's are
excellent. They follow from three placement errors:

1. **Decisions scattered across entry points.** The controller checks
   ownership, the console command doesn't, the queued job can't. Every new
   entry point re-decides (or forgets).
2. **Rules that omit scope.** "User owns the order" passes when the user id
   matches — even across tenants. Ownership without tenant scope is an IDOR
   with extra steps.
3. **Denials untested.** The happy path ("owner edits order") is tested; the
   forty ways to be denied are not — and every one of them is a security
   incident waiting for the right request.

## When to use it

- **Policies** when the ability is *about a resource* (`update` an Order,
  `view` an Invoice) — the resource-shaped rule home.
- **Gates** when the ability has no natural resource (`view-dashboard`,
  `import-data`) or is an admin threshold.
- **Policy `before()`** for explicit, auditable bypasses (admins/support) —
  visible in the policy file, never hidden in middleware.

## When NOT to use it

- **Authentication confused with authorization.** "Is this a valid user?"
  (authn) is middleware; "may this user do this?" (authz) is policies/gates.
  Conflating them is how "any logged-in user" becomes the rule for
  everything.
- **Roles as the only abstraction.** "Is the user an admin?" explodes into
  hardcoded role checks per action; model the *abilities* (policies) and let
  roles map onto them — not the reverse.
- **Authorization by obscurity.** Unlisted routes, hidden buttons, client-side
  only checks — none of these decide anything; the server decides.
- **Super-granular permission matrices on day one.** Abilities first; if the
  matrix genuinely grows, add a permission layer beneath the policies —
  measured, not speculative.

## The simplest correct implementation

The decision point — starting with **ownership only, deliberately
incomplete**: the tenant scope that makes it production-safe is the next
example (and the fixture the harness executes carries both rules).

<!-- example: authorization/policy-ownership -->
```php
<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    /**
     * The bypass is deliberate and visible: admins pass every ability.
     * Returning null means "no opinion — fall through to the rules".
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function update(User $user, Order $order): bool
    {
        // DELIBERATELY INCOMPLETE: ownership alone — in a multi-tenant
        // system this is an IDOR with extra steps. The production-safe
        // version (next example) adds the tenant scope.
        return $user->id === $order->customer_id;
    }
}
```

```php
<?php

// Registered once (AppServiceProvider):
use Illuminate\Support\Facades\Gate;
Gate::policy(Order::class, OrderPolicy::class);
```

```php
<?php

// Enforced at EVERY entry point through the same question:
if ($user->can('update', $order)) { /* ... */ }

// In HTTP adapters, the framework form — 403 without a manual branch:
$this->authorize('update', $order);
```

One rule, one home; every entry point asks the same question and gets the same
answer — including the ones added next year.

## What production requires

### Tenant scope is part of the rule, not an afterthought

<!-- example: authorization/tenant-scope -->
```php
<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function update(User $user, Order $order): bool
    {
        // Ownership AND tenant scope: a matching user id across tenants is
        // still denied. In multi-tenant systems this line is the security
        // boundary — the ownership check alone is an IDOR.
        return $user->tenant_id === $order->tenant_id
            && $user->id === $order->customer_id;
    }
}
```

The same scope discipline applies to **queries**, not just policies: a
user-scoped query that forgets the tenant filter reads other tenants' rows
even when every write is policy-checked — the
[caching](../data/caching.md) cross-tenant key incident has an authorization
twin. Belt and suspenders: policies for decisions, scoped queries for
retrieval.

### Denials that are visible and loud

- **403 ≠ 404 policy, decided once.** Hiding existence (404) vs stating
  refusal (403) is a per-resource decision — write it down; mixing them
  randomly leaks information either way.
- **Denials are logged with actor + resource** — a spike in 403s is either an
  attacker or a broken client, and you can't tell which without context
  ([observability](../quality/observability.md) request ids apply).
- **The `before()` bypass is audited** — admin actions get their own log
  trail; a bypass without an audit line is an unaccountable god mode.

### The rule inventory

Every ability maps to exactly one policy method, and every route/job that
touches the resource calls it. The mechanical check: grep the entry points for
`can(`/`authorize(` — a mutating route without one is a finding, not a style
note.

## What usually goes wrong

1. **IDOR via the forgotten endpoint.** *Root cause:* the UI flow's controller
   checks `authorize()`; the API controller (or the queued job's callback
   route) doesn't. *Detection:* security review or an attacker — in that
   order. *Fix:* the policy as the single decision point + the entry-point
   grep gate; policies make forgetting *visible* (the method exists, the call
   is missing).
2. **Tenant scope omitted from the rule.** *Root cause:* single-tenant
   thinking surviving the multi-tenant migration. *Detection:* the cross-tenant
   test (⚙️ below) fails — or a customer reports seeing another tenant's
   order. *Fix:* scope in every rule; scope in every read query.
3. **`before()` as an accidental god mode.** *Root cause:* `return true;` left
   from debugging, or `is_admin` set true by a data migration gone wrong.
   *Detection:* audit trail shows admins performing non-admin flows. *Fix:*
   bypass returns audited and narrow; the flag treated as a production datum
   with change control.
4. **Client-side authorization.** *Root cause:* hiding buttons instead of
   enforcing server rules. *Detection:* direct API calls performing hidden
   actions. *Fix:* server decides (policies); the client hides what the
   server already denies.
5. **Gate closure sprawl.** *Root cause:* `Gate::define(...)` calls
   accumulated in a provider until nobody knows the rule list. *Detection:*
   duplicate/near-duplicate closures with drifting logic. *Fix:* policies per
   resource; gates reserved for resource-less abilities; the inventory review.
6. **Testing only the grants.** *Root cause:* the owner-can-edit test exists;
   the stranger-can't, cross-tenant-can't, and expired-permission-can't tests
   don't. *Detection:* the incident. *Fix:* every ability ships with its
   denials — the harness pattern below is the template.

## How to test it

- **Every ability tests its matrix:** owner ✓, stranger ✗, admin (bypass) ✓
  **and** the scope matrix: same-tenant ✓, cross-tenant ✗ even with matching
  ids (⚙️ `examples/tests/AuthorizationExamplesTest.php` — both claims
  executed).
- **Registration is part of the claim:** a policy that isn't registered
  authorizes nothing (Gate falls back to denial) — the harness registers via
  `Gate::policy` and the tests would fail if the wiring broke.
- **Denial shape:** assert the 403 (or your chosen hiding) at the adapter
  level once per resource — the policy tests own the logic, the HTTP test
  owns the status code.
- **The grep gate:** CI check that every mutating route file references
  `authorize|can|policy` — mechanical, blunt, effective.

## How to operate it

### Per-route policy registries

As routes multiply, "which policy guards this route?" needs an answer that
isn't per-controller archaeology. The registry pattern keeps the answer
mechanical:

```php
<?php

// A dedicated service provider's boot() — the route→ability map. The
// argument names the ROUTE PARAMETER (`invoice`), so the middleware
// resolves the bound model and the policy receives the instance:
Route::middleware('can:view,invoice')->group(function () {
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/invoices/{invoice}/lines', [InvoiceController::class, 'lines']);
});
```

Two argument forms, deliberately different: `'can:view,invoice'` (route
parameter) resolves the bound model and hands the policy the INSTANCE; the
FQCN form (`'can:create,App\Models\Invoice'`) is for instance-less
abilities (create-style checks where there is no row yet) — using the FQCN
form for an instance check hands the policy a class *string* instead of the
model, which with a typed policy method is a 500, not a 403.

Inline `can:` middleware per route, grouped per resource — the policy name
appears ON the route, so a grep over the route files IS the authorization
inventory ([modular monolith](../architecture/modular-monolith.md) gates use
the same grep principle). The alternative — resolving policies implicitly
by model type-hint — works, but the explicit registry makes the annual
"audit every route's rule" review a listing exercise instead of an
investigation.

- **403 telemetry:** rate by endpoint and by consumer — spikes mean an
  attacker, a broken client, or a rule change that surprised someone;
  each has a different owner.
- **Admin-action audit trail:** every `before()`-bypassed action logged with
  actor + resource + ability — reviewed like any privileged operation.
- **Permission-change review:** rule changes are security changes — they get
  the same review weight as the code they protect, with the policy diff in
  the PR.
- **3am runbook:** "user X saw user Y's data" → which endpoint → which rule
  missed the scope → fix in the policy (one place) → add the failing case to
  the policy test matrix before shipping.

## How it scales

- **Policies scale with resources; gates with platform abilities** — the
  matrix grows but every rule keeps one home.
- **Permission layers** (roles → permissions → abilities) enter when the
  matrix needs runtime configurability — a deliberate layer *beneath*
  policies, not replacing them: policies stay the question, permissions
  become one answer's source.
- **Multi-tenant at scale:** tenant scope in policies + scoped queries + the
  key naming/registry disciplines of [caching](../data/caching.md) and
  [redis](../data/redis.md) — the same isolation line drawn in four places,
  all enforced, all tested.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Policies / gates / `before()` hooks / `Gate::policy` | ✅ | ✅ | ✅ | ✅ |
| `$user->can()` / `authorize()` in controllers | ✅ | ✅ | ✅ | ✅ |
| `Gate::before` (global, provider-level) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

Authorization's framework surface is among the most stable in Laravel — the
version-sensitive risks live in what the rules protect, not in the mechanism.

## Related

- [API design](api-design.md) — the transport contract the authorization
  results render through (403 vs 404, error envelope).
- [Idempotency](../messaging/idempotency.md) — retry-safe writes that policies
  gate.
- [Caching](../data/caching.md) / [Redis](../data/redis.md) — the tenant-scope
  line in key naming and store isolation.
- [The modular monolith](../architecture/modular-monolith.md) — policies belong
  to the module that owns the resource.
- [Observability](../quality/observability.md) — denial telemetry and admin
  audit trails.
- Roadmap: permission management UIs, row-level security at the database.
