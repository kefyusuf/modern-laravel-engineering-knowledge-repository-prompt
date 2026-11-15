<?php

namespace MLE\Examples\Tests\Fixtures\Authorization;

use MLE\Examples\Tests\Fixtures\Models\Order;
use MLE\Examples\Tests\Fixtures\Models\User;

/**
 * authorization/policy-ownership + authorization/tenant-scope: the policy
 * is the single decision point. The executed tests walk both claims: the
 * ownership rule (owner granted, stranger denied, admin bypass visible) and
 * the tenant scope that completes it (matching user id ACROSS tenants is
 * still denied — the IDOR the ownership-only sketch would allow).
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
