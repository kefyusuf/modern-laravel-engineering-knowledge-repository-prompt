<?php

use Illuminate\Support\Facades\Gate;
use MLE\Examples\Tests\Fixtures\Authorization\OrderPolicy;
use MLE\Examples\Tests\Fixtures\Models\Order;
use MLE\Examples\Tests\Fixtures\Models\User;

uses()->group('authorization');

/**
 * Executed claims for chapters/interfaces/authorization.md.
 */
it('grants owners and admins, denies everyone else — at one decision point', function () {
    /** @example-id authz/policy-ownership */
    Gate::policy(Order::class, OrderPolicy::class);

    $order = new Order([
        'customer_id' => 10,
        'tenant_id' => 1,
        'status' => 'pending',
    ]);

    $owner = new User(['tenant_id' => 1, 'is_admin' => false]);
    $stranger = new User(['tenant_id' => 1, 'is_admin' => false]);
    $admin = new User(['tenant_id' => 9, 'is_admin' => true]);
    // ids: ownership compares the user's id to the order's customer_id.
    $owner->id = 10;
    $stranger->id = 11;

    expect($owner->can('update', $order))->toBeTrue();
    expect($stranger->can('update', $order))->toBeFalse();
    // The admin bypass: a different tenant, still authorized — deliberately.
    expect($admin->can('update', $order))->toBeTrue();
});

it('denies cross-tenant access even when the user id matches', function () {
    /** @example-id authz/tenant-scope */
    Gate::policy(Order::class, OrderPolicy::class);

    // Same customer id (10), DIFFERENT tenant: the ownership rule alone
    // would authorize this — the tenant scope is what keeps multi-tenant
    // data isolated.
    $crossTenantUser = new User(['tenant_id' => 2, 'is_admin' => false]);
    $crossTenantUser->id = 10;

    $order = new Order([
        'customer_id' => 10,
        'tenant_id' => 1,
        'status' => 'pending',
    ]);

    expect($crossTenantUser->can('update', $order))->toBeFalse();
});

beforeEach(function () {
    Gate::policy(Order::class, OrderPolicy::class);
});
