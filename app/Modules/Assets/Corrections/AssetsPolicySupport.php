<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\DocumentAction;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): what the things-register policies
 * share. Every action here is one the module runs itself (Delete, Dispose,
 * Undo disposal, Cancel, Reverse a hand-over or an import, Reopen): none
 * posts a journal, so there is no date exception and no Tk 20,000 second
 * person; the reason is always required (FINAL-PLAN 6.1).
 */
final class AssetsPolicySupport
{
    /**
     * @param  list<string|Blocker>  $blockers  plain sentences or blockers
     */
    public static function plain(DocumentAction $action, User $user, string $permission, array $blockers, string $reasonContext, bool $primary = true, ?string $verb = null): ActionAvailability
    {
        $list = array_map(fn ($b) => $b instanceof Blocker ? $b : new Blocker(self::code($action), $b), $blockers);

        if (! $user->can($permission)) {
            array_unshift($list, new Blocker('no_permission', 'You are not allowed to '.($verb ?? mb_strtolower($action->label())).' this record.'));
        }

        return new ActionAvailability(
            action: $action,
            allowed: $list === [],
            blockers: $list,
            permission: $permission,
            primary: $primary,
            requiresReason: true,
            reasonContext: $reasonContext,
            approval: ApprovalRoute::None,
        );
    }

    public static function notOffered(DocumentAction $action, string $what): Blocker
    {
        return new Blocker('not_offered', "{$action->label()} is not offered on {$what}.");
    }

    private static function code(DocumentAction $action): string
    {
        return 'blocked_'.$action->value;
    }
}
