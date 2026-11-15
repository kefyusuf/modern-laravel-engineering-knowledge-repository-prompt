<?php

use MLE\Examples\Tests\Fixtures\Models\Order;

uses()->group('row-level-security');

/**
 * Executed claims for chapters/data/row-level-security.md.
 */
it('filters every read by the tenant context, structurally', function () {
    /** @example-id row-level-security/global-tenant-scope */

    // Two tenants' orders exist in the SAME table...
    foreach (range(1, 3) as $i) {
        Order::create(['customer_id' => 10, 'tenant_id' => 1, 'sku_id' => $i, 'status' => 'open', 'quantity' => 1, 'placed_at' => now()]);
    }
    foreach (range(11, 13) as $i) {
        Order::create(['customer_id' => 10, 'tenant_id' => 2, 'sku_id' => $i, 'status' => 'open', 'quantity' => 1, 'placed_at' => now()]);
    }

    // ...but the context makes "all orders" mean "MY tenant's orders":
    Order::$currentTenantId = 1;

    $visible = Order::query()->get();
    expect($visible)->toHaveCount(3);
    expect($visible->pluck('tenant_id')->unique()->all())->toBe([1]);

    // The scoped model's count/aggregates inherit the filter too.
    expect(Order::query()->count())->toBe(3);
    expect(Order::query()->where('sku_id', 12)->exists())->toBeFalse(); // tenant 2's row

    // The escape hatch is explicit and documented behavior — used by
    // reviewed admin tooling, never by product code paths.
    $unfiltered = Order::query()->withoutGlobalScopes()->get();
    expect($unfiltered)->toHaveCount(6);
});

afterEach(function () {
    Order::$currentTenantId = null;
});
