---
title: Boundaries
topic: boundaries
status: draft
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
---

# Boundaries

"Domain / application / infrastructure" is not folder decoration — it is a
dependency rule: **the domain (your business rules) depends on nothing; the
application layer orchestrates the domain; infrastructure (HTTP, Eloquent,
queues, Redis) depends inward and is replaceable.** Laravel does not enforce
this by default — its conventions happily wire HTTP controllers straight into
Eloquent models — so this chapter translates the layering into Laravel artifacts
and the rules that keep them honest. It pairs with
[the modular monolith](modular-monolith.md), which draws the same lines *between*
modules; this chapter draws them *within* one.

## TL;DR

- **Use it when:** a codebase has enough business logic that "where does this
  rule live?" is a real question — the moment pricing, state machines, or
  invariants outgrow CRUD.
- **Don't use it when:** the app is CRUD-shaped — a three-layer ceremony around
  thin models costs velocity and buys nothing
  ([CQRS](cqrs.md) level 1 is the only separation it needs).
- **The classic production incident:** a pricing rule duplicated across a
  controller, a job, and a command — each with subtle differences — because no
  layer "owned" the rule. Second classic: business logic that can't run without
  HTTP (a console command "can't" place an order because the rule lives in a
  FormRequest).

## Problem

Without boundaries, every class implicitly depends on everything: the domain
rule ("an order over 500 ships free") ends up entangled with the framework —
woven into a controller, a FormRequest, an Eloquent model event. The costs:

1. **The rule can't be reused** across entry points (HTTP, console, queue) —
   so it's copied.
2. **The rule can't be tested without the framework** — slow suites, mock-heavy
   tests, [testing](../quality/testing.md) discipline erodes.
3. **Infrastructure changes detonate** — swapping Eloquent for a read model, or
   HTTP for a message handler, means rewriting business rules.

The layers fix exactly this by making dependency direction explicit.

## When to use it

- Multiple entry points share business rules (web + API + console + queue).
- Invariants you could state to a domain expert ("orders ship free over 500")
  that don't fit in one model method.
- Teams large enough that "just look at the controller" stopped working.

## When NOT to use it

- **Thin CRUD.** If every action is "read the request, touch one model,
  respond", layering is ceremony — Laravel's defaults are the right tool.
- **As a gateway to framework purism.** You WILL use Eloquent in
  infrastructure; you will NOT abstract it behind repositories "just in case"
  (see the Eloquent question below, and [CQRS](cqrs.md) for where a real seam
  justifies itself).
- **Before the second entry point exists.** Extract the rule into the domain
  when two callers need it — not in anticipation of one.

## The layers, in Laravel terms

| Layer | Owns | Laravel artifacts | May depend on |
|---|---|---|---|
| **Domain** | Business rules, invariants, state transitions; plain PHP | Value objects, entities (possibly Eloquent — see below), domain events (definitions), exceptions | **Nothing** — no HTTP, no DB, no facades |
| **Application** | Use cases: orchestrate domain + infrastructure to serve an entry point | Actions/Command handlers, queued jobs' orchestration logic, transaction boundaries ([Transactions](../data/transactions.md)), event dispatch decisions ([outbox](../messaging/outbox.md)) | Domain + infrastructure interfaces |
| **Infrastructure** | Framework mechanics | Controllers, FormRequests, Eloquent models & migrations, queue drivers, mailers, [Redis](../data/redis.md) clients | Application (calls it) + everything |
| **Interface(s)** | Delivery: HTTP, console, queue consumers | Routes, controllers, Artisan commands, queue job classes | Application |

Read the "May depend on" column as arrows pointing **inward**: infrastructure
knows about the application and domain; the domain knows nothing of either. The
practical test for any class: *"can I instantiate it and run its rules in a
plain PHP script with no framework booted?"* — if yes (domain), it will test in
milliseconds and survive framework migrations.

### The Eloquent question, answered honestly

Where does an Eloquent model sit? Two defensible answers — pick per module and
write the choice down:

1. **Model as infrastructure + domain entity combined** (the pragmatic default):
   the model holds behavior that is genuinely domain logic (invariants, state
   transitions) AND persistence. Accepted cost: the domain depends on Eloquent.
   Keep domain rules in the model or in plain classes it uses; keep SQL/persistence
   concerns (scopes, query shapes) in the [query objects](cqrs.md).
2. **Separate domain entities + Eloquent persistence models** with mapping:
   maximum purity, real mapping cost. Justified only when the domain is complex
   AND long-lived — most products never reach the point where mapping pays.

What is NOT defensible: business rules in controllers or FormRequests (rule 1
below) — that's the failure mode this layering exists to prevent. And what is
equally not defensible: a repository interface with exactly one Eloquent
implementation and no test or boundary value — speculative abstraction, deleted
on sight.

## The simplest correct implementation

One rule, three layers, minimal ceremony:

```php
<?php

namespace App\Domain\Orders;

/**
 * DOMAIN: the business rule, plain PHP, zero framework dependencies.
 * Instantiate and test it in a bare script — no HTTP, no DB, no facade.
 */
final class ShippingPolicy
{
    public function shippingFeeCents(int $orderTotalCents): int
    {
        // The rule an expert could state: "orders over 500 ship free."
        return $orderTotalCents >= 500_00 ? 0 : 499;
    }
}
```

```php
<?php

namespace App\Application\Orders;

use App\Domain\Orders\ShippingPolicy;

final class CheckoutOrder
{
    /**
     * APPLICATION: orchestrates the domain (the rule) and infrastructure
     * (persistence via the model) for ANY entry point. Owns the transaction
     * boundary (Transactions chapter).
     */
    public function __construct(
        private readonly ShippingPolicy $shipping,
    ) {}

    public function execute(array $cart): int
    {
        $totalCents = array_sum(array_column($cart, 'price_cents'));
        $feeCents = $this->shipping->shippingFeeCents($totalCents);

        // Persistence happens here (infrastructure), orchestrated by the
        // use case — the RULE never touched the database.
        return \App\Models\Order::create([
            'total_cents' => $totalCents,
            'shipping_fee_cents' => $feeCents,
        ])->id;
    }
}
```

```php
<?php

namespace App\Http\Controllers;

use App\Application\Orders\CheckoutOrder;

final class CheckoutController
{
    /**
     * INTERFACE: validates (via a FormRequest) and delegates. Zero rules.
     */
    public function store(CheckoutRequest $request, CheckoutOrder $checkout)
    {
        $orderId = $checkout->execute($request->validated('items'));

        return response()->json(['order_id' => $orderId], 201);
    }
}
```

Now the same rule serves a console command, a queued retry, and an API — with
one test for it, in milliseconds, without booting the framework.

## What production requires

### Numbered, enforceable dependency rules

1. **Business rules live in the domain (or a domain-owned model method)** —
   never in controllers, FormRequests, middleware, or Blade. A FormRequest
   validates *transport shape* ("items is a non-empty array of positive
   ints"), never *business meaning* ("the cart may contain at most 10 of a
   limited SKU" — that's domain).
2. **Controllers/commands/job classes are delivery adapters** — validate,
   delegate to an application use case, shape the response. No rules, no
   direct model writes.
3. **Application use cases own transaction boundaries and event dispatch** —
   one use case = one transaction scope
   ([Transactions](../data/transactions.md),
   [modular monolith](modular-monolith.md) rule 5).
4. **The domain does not import framework namespaces.** Static analysis
   enforces it: deptrac layers per directory
   ([modular monolith](modular-monolith.md) shows the config shape) with a
   rule "Domain may depend on nothing under App\Infrastructure".
5. **Infrastructure interfaces are declared where they're consumed** — the
   application defines the port (an interface for "payment gateway") and
   infrastructure implements it, not the reverse. But: no interface with
   exactly one implementation and zero test/boundary value — that's
   speculative abstraction ([decisions](../../docs/decisions/) — the content
   standard's no-speculation rule, applied to code).

### Testing consequences

- **Domain tests:** plain PHPUnit/Pest, no Laravel TestCase — milliseconds.
- **Application tests:** Testbench boots the framework; assert orchestration
  (the use case persisted, dispatched, committed) with fakes at the edges.
- **Interface tests:** HTTP/console delivery only — the shapes the entry point
  produces ([testing](../quality/testing.md) triad applies).

## What usually goes wrong

1. **Rules in FormRequests.** *Root cause:* validation feels like the natural
   home for "at most 10 per SKU". *Detection:* the same rule restated in a
   controller or a second FormRequest diverges. *Fix:* transport checks stay;
   business meaning moves to the domain/use case.
2. **Fat controllers with direct model writes.** *Root cause:* velocity. Every
   extra entry point re-implements the flow. *Detection:* a console command
   duplicating a controller's logic; rules only reachable via HTTP. *Fix:* the
   use-case layer; controllers become adapters.
3. **Repository-everything reflex.** *Root cause:* "clean architecture"
   cargo-culted: an interface + Eloquent repository for every model, all
   pass-through. *Detection:* interfaces with one implementation, methods that
   only delegate. *Fix:* delete them; Eloquent IS the persistence boundary
   until a real seam appears ([CQRS](cqrs.md) read models are the seam that
   usually justifies one).
4. **Domain events defined in infrastructure.** *Root cause:* event classes
   filed next to listeners under the framework tree; consumers import
   transport-shaped payloads. *Detection:* renaming a queue connection breaks
   domain imports. *Fix:* events are domain contracts
   ([outbox](../messaging/outbox.md) payload discipline applies).
5. **Layer envy without boundaries.** *Root cause:* folders named Domain/
   whose classes import facades freely — layering as decoration. *Detection:*
   deptrac (or a simple grep gate) fails on every file. *Fix:* enforce or
   delete the folders — an unenforced boundary is worse than none, because it
   lies.
6. **Transaction sprawl across layers.** *Root cause:* transactions opened in
   controllers AND use cases AND models. *Detection:* nested-transaction
   surprise ([Transactions](../data/transactions.md) savepoint semantics).
   *Fix:* exactly one owner per flow — the application use case.

## How to test it

- **The framework-free test is the boundary test:** if a domain class needs a
  booted app, a boundary violation already happened — a grep/architecture gate
  (`grep -r "Illuminate\\\\" app/Domain/` failing CI) mechanizes it.
- **Use-case tests:** boot Testbench, fake the edges (queue, mail), assert the
  orchestration — real persistence, faked delivery.
- **Entry-point tests:** thin — the adapter's shape (status codes, command
  output), not rules ([testing](../quality/testing.md)).
- The behavioral claims this chapter relies on (transaction semantics, event
  delivery) have their ⚙️ coverage in the referenced chapters; the layering
  itself is a structure claim — verified by static analysis, not by execution.

## How to operate it

- **The boundary review is a PR conversation** with a mechanical gate (deptrac
  or grep) — the gate catches drift, the conversation catches misplacement.
- **Ownership:** each layer's README (one paragraph per layer in the module
  doc — [modular monolith](modular-monolith.md)) states the rules; onboarding
  reads rules, not archaeology.
- **3am runbook:** incident in a rule → the domain class is the single place
  to fix it — that's the payoff of the layering, visible precisely when
  production hurts.

## How it scales

- **The layering scales by staying boring:** the domain grows (more policies,
  entities), the application grows (more use cases), infrastructure grows with
  the framework — the dependency arrows never flip.
- **When modules appear** ([modular monolith](modular-monolith.md)), each
  module carries this layering internally; the module boundary is the stronger
  line, the layers the weaker one.
- **Extraction readiness** — a use-case layer over a framework-free domain is
  exactly what makes later service extraction a deployment change rather than
  a rewrite.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Layering applicability (providers, FormRequests, events, jobs) | ✅ | ✅ | ✅ | ✅ |
| Slim skeleton (`bootstrap/app.php` — middleware/route wiring) | Kernel-based | ✅ | ✅ | ✅ |
| `casts()` method (model layering ergonomics) | ❌ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The dependency rule is framework-version-agnostic; the skeleton change (10 →
11) only moves the wiring surface.

## Related

- [The modular monolith](modular-monolith.md) — the same dependency rule
  between modules; this chapter is its intra-module half.
- [CQRS](cqrs.md) — where real read/write seams justify their abstraction.
- [Transactions](../data/transactions.md) — the boundary the application layer
  owns.
- [The transactional outbox](../messaging/outbox.md) — how domain events cross
  to infrastructure reliably.
- [Testing the failure paths](../quality/testing.md) — the testing consequences
  of the layering.
- Roadmap: domain-driven design (aggregates, ubiquitous language), event-driven
  architecture.
