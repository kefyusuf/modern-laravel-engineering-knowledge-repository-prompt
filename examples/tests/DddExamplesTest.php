<?php

uses()->group('ddd');

/**
 * Executed claims for chapters/architecture/ddd.md.
 */
it('enforces aggregate invariants at every mutation', function () {
    /** @example-id ddd/aggregate-invariant */
    $order = new \MLE\Examples\Tests\Fixtures\Domain\PurchaseOrder(1);

    // Invalid mutation: rejected by the aggregate, not by a controller check.
    expect(fn () => $order->addLine(1, 0, 500))
        ->toThrow(\MLE\Examples\Tests\Fixtures\Domain\DomainException::class);

    $order->addLine(1, 2, 500);
    $order->addLine(2, 1, 100);

    // The invariant the aggregate OWNS: ten lines is the ceiling — and the
    // eleventh attempt fails no matter which entry point made it.
    foreach (range(3, 10) as $sku) {
        $order->addLine($sku, 1, 100);
    }
    expect(fn () => $order->addLine(11, 1, 100))
        ->toThrow(\MLE\Examples\Tests\Fixtures\Domain\DomainException::class);

    // Immutable after cancellation — the state machine lives here too.
    $order->cancel();
    expect(fn () => $order->addLine(12, 1, 100))
        ->toThrow(\MLE\Examples\Tests\Fixtures\Domain\DomainException::class);

    // The aggregate computes its own totals — correct by construction.
    expect($order->totalCents())->toBe(1900);
});
