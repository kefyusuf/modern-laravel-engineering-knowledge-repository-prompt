<?php

namespace MLE\Examples\Tests\Fixtures\Authorization;

use MLE\Examples\Tests\Fixtures\Models\Order;
use MLE\Examples\Tests\Fixtures\Models\User;

/**
 * authz/policy-ownership + authz/tenant-scope: the policy is the single
 * decision point — ownership AND tenant scope — with an explicit admin
 * bypass in before().
 */
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
        // Ownership AND tenant scope: a user can only touch their own
        // order INSIDE their own tenant — a matching customer_id across
        // tenants is still denied.
        return $user->tenant_id === $order->tenant_id
            && $user->id === $order->customer_id;
    }
}
