<?php

namespace App\Modules\Hrm\Dashboard\Concerns;

use App\Models\User;

/**
 * The ordinary answer to "may this user see the tile": do they hold the
 * permission it declares.
 *
 * Said once here rather than in every widget, for the same reason `CountsRows`
 * exists on the reporting side — a rule repeated per class is a rule the next
 * class gets wrong.
 *
 * ⚠ **This is only the permission half.** C10d, ACL-02 and the fail-closed
 * scope rules live in `DashboardRegistry` and are applied on top, so a widget
 * using this trait cannot accidentally opt out of them.
 */
trait VisibleByPermission
{
    public function isVisibleTo(User $user): bool
    {
        return $user->can($this->permission());
    }
}
