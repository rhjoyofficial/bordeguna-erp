<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Emails everyone in a business who can approve a given kind of request that
 * one is now pending. Reusable across the generic approval pipeline: the
 * caller supplies the business, the approve-permission, and a one-line
 * summary, and the recipients are resolved from who actually holds that
 * permission here — so a new approvable type wires in with one call.
 */
class NotifyApprovers
{
    public function handle(int $businessId, string $permission, string $summary, ?int $excludeUserId = null): void
    {
        // An undefined permission simply has no holders — nothing to send.
        if (! Permission::where('name', $permission)->where('guard_name', 'web')->exists()) {
            return;
        }

        // Resolve "who can approve" within this business's team, not ambient.
        app(PermissionRegistrar::class)->setPermissionsTeamId($businessId);

        $recipients = User::permission($permission)
            ->where('is_active', true)
            ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $businessId))
            ->when($excludeUserId, fn ($query) => $query->where('id', '!=', $excludeUserId))
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PendingApprovalNotification($summary, route('approvals.index')));
        }
    }
}
