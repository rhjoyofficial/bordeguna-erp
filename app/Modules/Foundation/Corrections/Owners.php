<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;

/**
 * Who counts as the Owner for a closed month (FINAL-PLAN section 9, answer 3:
 * "Closed months: Owner only").
 *
 * ⚠⚠ A PERSON, NOT A PERMISSION. The Owner ROLE (or the super admin, which this
 * codebase treats as the Owner everywhere: DisbursementController,
 * RecordConsignmentOutcome) AND the permission accounting.correct.closed_period.
 * A Finance user handed the permission in Settings > Roles still fails, because
 * the role check comes first. Production super admin = Joy (user 16).
 */
final class Owners
{
    public static function is(?User $user): bool
    {
        // ⚠ `=== false`: a freshly created model has no is_active attribute
        // until it is re-read (the column defaults to true in the database).
        if ($user === null || $user->is_active === false) {
            return false;
        }

        return (bool) $user->is_super_admin || $user->hasRole('Owner');
    }

    public static function mayCorrectClosedMonth(?User $user): bool
    {
        return self::is($user) && $user->can('accounting.correct.closed_period');
    }
}
