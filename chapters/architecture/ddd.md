---
title: Domain-driven design in Laravel
topic: ddd
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/DddExamplesTest.php
---

# Domain-driven design in Laravel

Domain-driven design is a vocabulary for keeping software shaped like the
business it serves: **bounded contexts** own their language, **aggregates**
own their consistency, **value objects** own their invariants, and the
framework — Laravel — is infrastructure at the edge, not the architecture at
the center. This chapter is the pragmatic DDD translation: which of DDD's
ideas pay for themselves in a Laravel codebase, in which Laravel artifacts
they live, and where the ceremony stops paying. It builds on
[boundaries](boundaries.md) (layers) and [the modular monolith](modular-monolith.md)
(modules).

## TL;DR

- **Use it when:** the domain has real rules an expert could teach you —
  state machines, invariants, calculations whose correctness is the product.
- **Don't use it when:** the app moves rows around — DDD ceremony on CRUD is
  negative value ([boundaries](boundaries.md)' "when not" applies first).
- **The classic production incident:** the order that CAN be shipped while
  cancelled, because the rule lived in a controller that one entry point
  skipped. DDD's aggregate exists precisely so that rule has one home and
  cannot be bypassed.

## Problem

As domains grow, three failure modes dominate:

1. **Invariants without a home.** "An order can't be shipped after
   cancellation" exists as a check in three places — and the fourth entry
   point skips it. Nothing *owns* the rule.
2. **Language drift.** Code says `status_flag_2`; the business says
   "dispatched". Every conversation needs translation; every translation is a
   bug opportunity.
3. **Consistency by accident.** An order and its lines are updated by
   different code paths in different transactions — half-updated aggregates
   appear ([Transactions](../data/transactions.md)).

DDD's tactical patterns (aggregate, value object, domain event) exist to give
rules, language, and consistency explicit homes.

## When to use it

- Multiple rules an expert would recognize as *theirs* (pricing, eligibility,
  state machines).
- Invariants that span several objects and must hold atomically (order +
  lines; subscription + invoices).
- Several bounded contexts whose languages genuinely differ (Billing's
  "customer" ≠ Support's "customer" — [modular monolith](modular-monolith.md)).

## When NOT to use it

- **CRUD-shaped domains.** A form over a table has no invariants to protect —
  layering ceremony here slows every feature ([boundaries](boundaries.md)).
- **As an excuse for speculative modeling.** Aggregates/entities/value
  objects for tables with no rules — the repository-aggregate complex costs
  more than the coupling it avoids.
- **All at once.** DDD is adopted per context: the Billing module can be
  DDD-shaped while Reporting stays query objects
  ([CQRS](cqrs.md)) — the architecture serves the domain's variance.

## The tactical patterns, in Laravel terms

| Pattern | What it is | Laravel home |
|---|---|---|
| **Bounded context** | A boundary inside which terms mean one thing | A module ([modular monolith](modular-monolith.md)) |
| **Aggregate** | A consistency boundary: one root, invariants enforced on every mutation, one transaction per aggregate | An Eloquent model (+ its relations) or a plain class persisted by an action |
| **Value object** | An immutable thing defined by its values (Money, DateRange) — equality by value | A `readonly` class (requires PHP ≥ 8.2 — a language capability, independent of the Laravel version) |
| **Domain event** | A fact the aggregate announces | Event class in the module's `Events/` ([outbox](../messaging/outbox.md) payload discipline) |
| **Ubiquitous language** | Code names what the business names | Class/method naming discipline — enforced in review, not tooling |
| **Anti-corruption layer** | A translation boundary against foreign models | A contract + adapter ([boundaries](boundaries.md) infrastructure layer) |

## The simplest correct implementation

The aggregate: one class that owns its invariants, mutable only through
intent-revealing methods.

<!-- example: ddd/aggregate-invariant -->
```php
<?php

namespace App\Domain\Orders;

final class PurchaseOrder
{
    /** @var array<int, array{sku_id: int, quantity: int, unit_cents: int}> */
    private array $lines = [];

    private bool $cancelled = false;

    public function __construct(private readonly int $id) {}

    /**
     * The ONLY way a line enters the order. The aggregate enforces its
     * invariants at every mutation — no code path can add a line that
     * violates them, because there is no other door.
     */
    public function addLine(int $skuId, int $quantity, int $unitCents): void
    {
        if ($this->cancelled) {
            throw new \DomainException('cancelled orders are immutable');
        }
        if ($quantity < 1) {
            throw new \DomainException('quantity must be positive');
        }
        if (count($this->lines) >= 10) {
            throw new \DomainException('an order carries at most 10 lines');
        }

        $this->lines[] = ['sku_id' => $skuId, 'quantity' => $quantity, 'unit_cents' => $unitCents];
    }

    public function cancel(): void
    {
        if ($this->lines === []) {
            throw new \DomainException('empty orders are not kept');
        }
        $this->cancelled = true;
    }

    public function totalCents(): int
    {
        return array_sum(array_map(
            fn (array $line) => $line['quantity'] * $line['unit_cents'],
            $this->lines,
        ));
    }
}
```

```php
<?php

namespace App\Application\Orders;

use App\Domain\Orders\PurchaseOrder;
use App\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

final class PlacePurchaseOrder
{
    public function __construct(private readonly Outbox $outbox) {}

    /**
     * The application layer persists the AGGREGATE in one transaction and
     * announces the fact (outbox chapter) — the invariant held before
     * anything touched the database.
     */
    public function execute(int $orderId, int $skuId, int $quantity, int $unitCents): void
    {
        $po = new PurchaseOrder($orderId);
        $po->addLine($skuId, $quantity, $unitCents);

        DB::transaction(function () use ($po, $orderId) {
            // Persist the aggregate's rows on the connection the transaction
            // owns (orders + order_lines — the aggregate's tables).
            DB::table('orders')->insert(['id' => $orderId, 'total_cents' => $po->totalCents()]);
            DB::table('order_lines')->insert([
                ['order_id' => $orderId, 'sku_id' => $skuId, 'quantity' => $quantity, 'unit_cents' => $unitCents],
            ]);

            $this->outbox->publish('order.placed', $orderId, [
                'schema_version' => 1,
                'order_id' => $orderId,
                'total_cents' => $po->totalCents(),
            ]);
        });
    }
}
```

(A real implementation reads the aggregate back through its repository of
tables; the shape above is the minimal honest version — the rules live in
`PurchaseOrder`, the action orchestrates persistence and the fact.)

The aggregate's rules are testable in **pure PHP, in milliseconds** — no
framework, no database ([boundaries](boundaries.md)' framework-free test as
the boundary test).

## What production requires

- **One aggregate, one transaction.** A transaction spanning two aggregates
  is either one aggregate or a distributed-consistency problem — cross-
  aggregate coordination goes through domain events
  ([outbox](../messaging/outbox.md)), not shared transactions
  ([modular monolith](modular-monolith.md) rule 5).
- **Aggregates are small.** The consistency boundary exists to be enforced —
  an aggregate spanning hundreds of objects serializes every write through
  its root ([database performance](../data/database-performance.md) hot-spot
  dynamics). Model the smallest boundary that protects the invariant.
- **Eloquent or not, rules live in the aggregate.** The pragmatic mapping
  ([boundaries](boundaries.md)' Eloquent question): Eloquent models as
  aggregate roots with invariants in domain methods — accepted cost, single
  rule home. What's not acceptable: the invariant ONLY in a FormRequest or a
  controller.
- **Domain events with contracts:** announced facts are versioned, id-and-
  value payloads ([outbox](../messaging/outbox.md)); consumers dedupe
  ([idempotency](../messaging/idempotency.md)).
- **Anti-corruption layers at the edges:** third-party APIs, legacy
  databases, and vendor SDKs never leak their shapes inward — a translation
  adapter owns the mapping ([boundaries](boundaries.md) infrastructure).

## What usually goes wrong

1. **The bypassed aggregate.** *Root cause:* a new entry point writes the
   aggregate's tables directly — faster today, invariant-violating forever.
   *Detection:* states that shouldn't exist (shipped-and-cancelled); audit
   shows the write path. *Fix:* the aggregate is the only writer; module
   boundaries + deptrac make direct writes visible
   ([modular monolith](modular-monolith.md)).
2. **The mega-aggregate.** *Root cause:* "the customer aggregate" containing
   every order, invoice, and address — one root, all writes serialized.
   *Detection:* lock contention on the root; transaction sizes growing with
   unrelated edits. *Fix:* smaller consistency boundaries; reference other
   aggregates by id, don't compose them.
3. **Value objects as arrays.** *Root cause:* `Money` replaced by
   `['cents' => ..., 'currency' => ...]` arrays passed around — invariants
   ("same currency to add") enforced nowhere. *Detection:* currency-mixing
   arithmetic bugs. *Fix:* readonly value objects with behavior; arrays stay
   at serialization edges ([caching](../data/caching.md) stores values, the domain
   stores meaning).
4. **Events named as commands.** *Root cause:* aggregates announcing
   `ShipOrder` instead of `OrderShipped` — request semantics leaking into
   facts ([eda](eda.md) bi-temporal confusion). *Detection:* consumers
   "rejecting" events. *Fix:* facts in past tense; commands go through use
   cases.
5. **DDD as folder decoration.** *Root cause:* `Domain/` full of anemic
   classes importing facades. *Detection:* the framework-free test fails —
   the "domain" needs a booted app. *Fix:* enforce or delete
   ([boundaries](boundaries.md) enforcement).
6. **Modeling for the demo, not the domain.** *Root cause:* aggregates,
  specifications, and CQRS everywhere because a conference talk said so —
   on a domain with three rules. *Detection:* feature velocity halved with
   no correctness change. *Fix:* keep the vocabulary; shrink the machinery
   to the domain's actual variance.

## How to test it

- **Aggregates test framework-free:** construct, mutate, assert the invariant
  exceptions and the totals — the fastest tests in the suite (⚙️
  `examples/tests/DddExamplesTest.php` executes the invariant claim).
- **Persistence tests:** the action layer saving the aggregate — assert the
  transaction boundary and the announced fact
  ([outbox](../messaging/outbox.md) harness patterns).
- **Language tests are review tests:** naming drift is caught in PRs — there
  is no assertion for a word.

## How to operate it

- **The domain expert is on the team:** rule changes arrive as language
  changes; the aggregate's methods are the audit surface for "what does the
  business currently do".
- **Rule changes are versioned behavior:** a tightened invariant may reject
  data that exists — migration plan before the rule lands
  ([deployment](../runtime/deployment.md) expand/contract applies to
  rules, not just columns).
- **3am runbook:** "the system did something impossible" → find the rule's
  home; if it has none, the fix is the aggregate — the incident is the
  modeling debt maturing.

## How it scales

- **Aggregates scale by staying small:** consistency boundaries are the
  write-contention units — small aggregates, id-references between them, and
  events for cross-aggregate coordination keep the write path parallel
  ([database performance](../data/database-performance.md)).
- **Contexts scale into modules and services** — the extraction readiness
  criteria ([modular monolith](modular-monolith.md)) are DDD's strategic
  patterns doing operational work.
- **The ceiling is language, not code:** the domain model's lifetime equals
  the business's vocabulary stability — DDD's real investment is keeping
  them identical.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `readonly` classes (value objects) | ⚠️ needs PHP ≥ 8.2 (a PHP capability — independent of the Laravel version; this repo's L10 CI cell runs PHP 8.2) | ⚠️ same | ⚠️ same | ⚠️ same |
| Pattern applicability (Eloquent as aggregate root, events, jobs) | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP (per matrix cell) | 8.1 | 8.2 | 8.2 | 8.3 |

DDD is framework-version-agnostic. The `readonly`-class row is a **PHP**
capability, not a Laravel one: a Laravel 10 app on PHP 8.2 parses it fine; a
Laravel 10 app on PHP 8.1 cannot — write plain immutable classes there
(private properties, no setters).

## Related

- [Boundaries](boundaries.md) — the layering DDD's patterns live inside.
- [The modular monolith](modular-monolith.md) — bounded contexts as modules;
  extraction readiness.
- [The transactional outbox](../messaging/outbox.md) — how aggregate events
  cross to infrastructure reliably.
- [Transactions](../data/transactions.md) — one aggregate, one transaction;
  the boundary rule.
- [CQRS](cqrs.md) — reads that outgrow the aggregate's shape.
- [Testing the failure paths](../quality/testing.md) — why domain tests run
  framework-free.
- Roadmap: domain events deeper patterns, saga/process-manager patterns.
