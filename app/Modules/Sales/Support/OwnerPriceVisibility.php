<?php

namespace App\Modules\Sales\Support;

use App\Models\User;

/**
 * Who may read that an owner sale was priced at the batch cost (D-B, Joy,
 * 18 Oct 2026): the Owner role and whoever reads costs
 * (`sales.reports.costs`, CostVisibility). Everybody else (a cashier) sees
 * the price the proprietor pays and nothing about how it was reached: not
 * the reference price beside it, not the "batch cost" trail.
 */
final class OwnerPriceVisibility
{
    public static function allows(?User $user): bool
    {
        return $user !== null && ($user->hasRole('Owner') || CostVisibility::allows($user));
    }
}
