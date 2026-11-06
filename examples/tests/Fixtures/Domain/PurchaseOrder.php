<?php

namespace MLE\Examples\Tests\Fixtures\Domain;

/**
 * ddd/aggregate-invariant — the aggregate as a consistency boundary: the
 * ONLY door for mutations, enforcing its invariants at every one of them.
 * Plain PHP — no framework, no database (that's the point).
 */
final class PurchaseOrder
{
    /** @var array<int, array{sku_id: int, quantity: int, unit_cents: int}> */
    private array $lines = [];

    private bool $cancelled = false;

    public function __construct(private readonly int $id) {}

    public function addLine(int $skuId, int $quantity, int $unitCents): void
    {
        if ($this->cancelled) {
            throw new DomainException('cancelled orders are immutable');
        }
        if ($quantity < 1) {
            throw new DomainException('quantity must be positive');
        }
        if (count($this->lines) >= 10) {
            throw new DomainException('an order carries at most 10 lines');
        }

        $this->lines[] = ['sku_id' => $skuId, 'quantity' => $quantity, 'unit_cents' => $unitCents];
    }

    public function cancel(): void
    {
        if ($this->lines === []) {
            throw new DomainException('empty orders are not kept');
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

final class DomainException extends \DomainException
{
}
