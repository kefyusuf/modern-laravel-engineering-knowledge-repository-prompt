---
title: The modular monolith
topic: modular-monolith
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-09"
---

# The modular monolith

A modular monolith is a single deployable with hard internal boundaries: modules own
their data, expose explicit contracts, and communicate through defined channels.
You buy deployment and transactional simplicity; you pay in discipline. The
discipline must be *enforced* — by namespace conventions, static analysis, and
communication rules — or the monolith quietly becomes a ball of mud with extra
folders.

## TL;DR

- **Use it when:** you have several bounded contexts in one product and want
  deployment simplicity with extraction options later — the default for most teams.
- **Don't use it when:** the codebase is one small context (modularity overhead
  exceeds benefit), or components genuinely need independent scaling/deployment.
- **The classic production incident:** "modular" code where every module imports
  every other module's Eloquent models. A column rename in `Billing` detonates
  across `Orders`, `Support`, and `Reporting` at once — the monolith's coupling with
  none of its honesty.

## Problem

Laravel's defaults optimize for getting started, and they quietly couple everything:
one `app/Models` directory, one `database/migrations` folder, one container where
every class is resolvable by everything, implicit dependencies through facades.
Six months in, no one can change `Invoice` without a codebase-wide search — because
there is no boundary, only convention.

The overcorrection is microservices: coupling becomes network calls, deployment
count multiplies, and a feature that was one PR becomes three services and a
migration plan. The modular monolith says: keep one deployable, restore the
boundaries.

## When to use it

- Multiple bounded contexts with distinct vocabularies (Billing vs Inventory vs
  Support) inside one product.
- Teams large enough that "everyone can edit everything" is a coordination tax, but
  not so large that deployment independence dominates.
- You want a credible *option* to extract a module later (the module you'd extract
  is designed to be extractable).
- You need transactional consistency across contexts today and can't justify
  distributed transactions ([Transactions](../data/transactions.md)).

## When NOT to use it

- **One context, small team.** CRUD-shaped products with a single vocabulary gain
  ceremony, not safety. Laravel's default layout is fine — see
  [CQRS](cqrs.md) for the read/write separation that *does* help there.
- **Genuinely independent components.** If a part needs different scaling, uptime,
  release cadence, or runtime, that's a service boundary, not a module boundary.
- **As a stepping stone you never walk.** "We'll modularize so we can split later"
  only works if the communication rules are enforced from day one; a mud ball is
  not easier to split than a monolith, it's harder.

## The simplest correct implementation

Two modules, one boundary crossing — the smallest structure that demonstrates every
rule. Directory layout:

```text
app/
  Modules/
    Billing/
      Http/Controllers/
      Commands/            # write-side actions (the module's API)
      Queries/             # read-side queries (the module's API)
      Contracts/           # what other modules may call
      Events/              # what this module publishes
      Listeners/           # what this module subscribes to
      Models/              # private to this module
      Database/Migrations/ # module-owned
      Routes/api.php
      Providers/BillingServiceProvider.php
      Tests/
    Orders/
      ... (same shape)
  Shared/                  # the shared kernel: User, money value objects, etc.
```

The boundary crossing — `Orders` needs a customer's billing status:

```php
<?php

namespace App\Modules\Billing\Contracts;

interface CustomerBillingQueries
{
    /**
     * The ONLY way Orders reads billing state: an explicit contract
     * returning a DTO — never the Invoice model, never the connection.
     */
    public function hasUnpaidInvoices(int $customerId): bool;
}
```

```php
<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Contracts\CustomerBillingQueries;
use App\Modules\Billing\Queries\UnpaidInvoiceQuery;

final class BillingModule implements CustomerBillingQueries
{
    public function __construct(private readonly UnpaidInvoiceQuery $unpaid) {}

    public function hasUnpaidInvoices(int $customerId): bool
    {
        return $this->unpaid->existsForCustomer($customerId);
    }
}
```

```php
<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Billing\Contracts\CustomerBillingQueries;

final class PlaceOrder
{
    // Type-hint the CONTRACT. The container resolves it; Orders never
    // references a Billing class beyond the contract namespace.
    public function __construct(private readonly CustomerBillingQueries $billing) {}

    public function execute(int $customerId, array $items): void
    {
        if ($this->billing->hasUnpaidInvoices($customerId)) {
            throw new \App\Modules\Orders\Exceptions\PaymentRequired($customerId);
        }

        // ... order placement ...
    }
}
```

```php
<?php

namespace App\Modules\Billing;

use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The module publishes its contract; the implementation stays inside.
        $this->app->bind(
            Contracts\CustomerBillingQueries::class,
            fn ($app) => new BillingModule($app->make(Queries\UnpaidInvoiceQuery::class)),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
    }
}
```

What this buys: `Orders` can be read and changed without knowing `Billing`'s
internals; `Billing`'s schema is free to change behind the contract; extraction of
either module starts from an already-defined interface.

## What production requires

### Communication rules (numbered and enforceable)

These are the module contract. Every rule maps to an enforcement mechanism below.

1. **Sync reads cross a boundary only through a `Contracts/` interface** returning
   DTOs/scalars — never another module's Eloquent models, never raw DB access to
   another module's tables.
2. **Writes cross a boundary only as commands or jobs** to the owning module's
   action — the owning module decides how and when to persist.
3. **Events are the publish/subscribe channel.** The publishing module owns the
   event class; subscribers in other modules listen — they never mutate the
   publishing module's tables in response.
4. **Database: one database, module-owned tables.** No foreign keys into another
   module's tables, no cross-module joins. Need related data? Call the contract or
   subscribe to the event and keep your own local copy.
5. **Transactions do not cross module boundaries.** One module's transaction may
   span only its own tables. Cross-module consistency = local transaction + outbox
   event ([Transactions](../data/transactions.md),
   [outbox](../messaging/outbox.md)).
6. **Shared kernel is small and stable:** `User`, base value objects, cross-cutting
   infrastructure (logging, clock). If it changes often, it's not kernel material.

### Enforcement — the part that makes it real

Conventions decay without a checker. The standard tool is [deptrac] — a static
analysis tool that maps class dependencies against declared rules and fails CI on
violations:

```yaml
# deptrac.yaml (sketch) — the rules above, mechanized
deptrac:
  paths:
    - ./app/Modules
  layers:
    - name: Orders
      collectors:
        - { type: directory, value: app/Modules/Orders/.* }
    - name: Billing
      collectors:
        - { type: directory, value: app/Modules/Billing/.* }
  ruleset:
    Orders:
      - Billing        # allowed — but only via Contracts, by convention + review
    Billing: []        # Billing may not depend on Orders (no cycles)
```

Keep `skip_violations` empty: every violation is a decision, not noise. Run it in CI
next to your tests — a violation is a pull-request conversation ("this needs a
contract, or a rule change"), which is exactly where that conversation belongs.
Without enforcement, rule 1 survives about two sprints.

Also per module, ownership artifacts:

- **Routes** loaded by the module's provider (works identically on Laravel 10's
  Kernel wiring and 11+'s `bootstrap/app.php` — the provider is the stable seam).
- **Migrations** in the module's directory, loaded via `loadMigrationsFrom()`.
- **Config** in the module namespace (`config/billing.php`), read via
  `config('billing.x')` — no god-config.
- **Tests** colocated; contract tests covering the `Contracts/` surface (see
  [testing](#how-to-test-it)).

## What usually goes wrong

1. **Model leakage.** *Root cause:* `use App\Modules\Billing\Models\Invoice` in an
   Order controller — one eager-loaded relation later, the boundary is fiction.
   *Detection:* deptrac in CI (missing = guaranteed eventual leakage); grep for
   cross-module `Models\` imports. *Fix:* the contract route; convert call sites.
2. **Cross-module transactions.** *Root cause:* one `DB::transaction` wrapping
   Order + Billing writes "because it's one database". *Detection:* code review —
   a transaction whose closures touch two module namespaces. *Fix:* local
   transaction + outbox event; the receiving module applies its own
   ([outbox](../messaging/outbox.md)).
3. **Shared migrations folder.** *Root cause:* habit. A central
   `database/migrations` means module boundaries don't include their schema.
   *Detection:* directory listing. *Fix:* `loadMigrationsFrom` per module; the
   migrator runs them all the same.
4. **Cross-module joins and FKs.** *Root cause:* "it's one database, join it."
   Performance is great until a module needs to own its storage. *Detection:* SQL
   log review; deptrac can't see SQL — this rule needs review discipline.
   *Fix:* contracts for reads; events for local copies of needed data.
5. **Circular module dependencies.** *Root cause:* Billing needs an Order fact,
   Orders needs a Billing fact, both import each other. *Detection:* deptrac fails
   (cycle), or worse, it's allowed and the tangle grows. *Fix:* one direction via
   events (publish the fact you own), or extract the shared concept.
6. **Premature modularity.** *Root cause:* a two-person product split into five
   modules — pure ceremony, slower than the flat alternative. *Detection:* module
   count vs team size vs context count. *Fix:* fewer, bigger modules; the boundary
   you keep is worth more than the ones you imagine.

## How to test it

- **Per-module suites:** each module's tests run against its own migrations —
  blast radius of a test run reflects the boundary.
- **Contract tests:** the consuming module tests against the *contract* (a fake or
  the bound implementation), so provider refactors don't break consumer tests and
  vice versa. This is also the test suite that moves with a module when extracted.
- **Architecture tests:** deptrac run in CI is the enforcement; add a smoke test
  that the container can resolve every published contract (a missing provider
  binding fails here, not in production).
- **Cross-module event tests:** publish in one module, assert the other module's
  listener effect — these tests ARE the integration contract.

## How to operate it

- **One deployable** — the ops story stays Laravel-simple: single pipeline, single
  release note. Guard this advantage: if a module starts needing its own deploy
  cadence, that's the extraction signal, not a reason to fake it.
- **Ownership map:** a README per module (what it owns, what it publishes, who
  consumes it) — the onboarding doc and the incident-time responsibility matrix in
  one file.
- **Boundary drift review:** deptrac report reviewed at milestone boundaries;
  violations trend is a code-health metric like test coverage.
- **3am runbook:** most incidents stay module-local — the ownership map routes the
  page. Cross-module incident = the event flow between two modules; start at the
  publisher's outbox/queue, not the consumer's logs.

## How it scales

- **Team scale:** modules become ownership units — the boundary that made review
  and testing cheaper also routes on-call and roadmaps.
- **The single database is the monolith's real ceiling:** write contention on
  shared tables and one migration history. It's a high ceiling — most products
  never reach it — and the first lever is per-module read models
  ([CQRS](cqrs.md)), not extraction.
- **Extraction path:** a module is extraction-ready when (a) its contracts are the
  only inbound surface, (b) it publishes events for everything it announces, (c)
  it owns its tables without cross-module joins. Then the extraction is a
  deployment change, not a rewrite.
- **Don't extract** until a module's scaling, uptime, or team needs genuinely
  diverge; network boundaries convert free consistency into engineering work.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Module pattern applicability (providers, migrations, routes, events) | ✅ | ✅ | ✅ | ✅ |
| Route/middleware registration (Kernel vs `bootstrap/app.php`) | Kernel-based | slim skeleton (`withRouting`/`withMiddleware`) | ✅ slim | ✅ slim |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The module pattern is framework-version-agnostic — it's namespaces, providers, and
discipline. The only version fork that touches it is the 10 → 11 skeleton change:
module providers register routes the same way in both, but the *central* wiring
lives in different files. Laravel 13's delta here is the PHP 8.3 minimum.

## Related

- [CQRS](cqrs.md) — the read/write separation that lives *inside* modules; the
  two patterns compose.
- [Transactions](../data/transactions.md) — rule 5's mechanics; why module
  boundaries and transaction boundaries should coincide.
- [The transactional outbox](../messaging/outbox.md) — rule 5's consistency
  mechanism for cross-module events.
- [Idempotency](../messaging/idempotency.md) — event consumers are dedupe
  consumers.
- [Caching](../data/caching.md) — per-module key ownership.
- Covered since this line was written: [DDD](ddd.md) and [event-driven
  architecture](eda.md). Deeper dives remain in the README roadmap.
