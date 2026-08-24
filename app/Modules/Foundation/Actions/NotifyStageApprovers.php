<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Emails everyone who can act on an approval request's CURRENT stage — resolved
 * from that stage's allowed roles and/or permissions — that it's waiting on
 * them. The stage-aware counterpart to NotifyApprovers (which targets a single
 * fixed permission): as a multi-stage request advances, each stage's own
 * approvers are notified in turn. No-op when the request has no workflow (the
 * legacy single-stage consumers keep using NotifyApprovers).
 */
class NotifyStageApprovers
{
    public function handle(ApprovalRequest $request, string $summary): void
    {
        $stage = $request->currentStage();

        if ($stage === null) {
            return;
        }

        $businessId = $request->business_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($businessId);

        $userIds = collect();

        foreach ($stage->allowed_roles ?? [] as $role) {
            if (Role::where('name', $role)->where('business_id', $businessId)->where('guard_name', 'web')->exists()) {
                $userIds = $userIds->merge(User::role($role)->pluck('id'));
            }
        }

        foreach ($stage->allowed_permissions ?? [] as $permission) {
            if (Permission::where('name', $permission)->where('guard_name', 'web')->exists()) {
                $userIds = $userIds->merge(User::permission($permission)->pluck('id'));
            }
        }

        // ⚠ HR's own approvals respect the HR notification preference (Joy,
        // 24 Aug 2026): permission decides who MAY approve, the preference
        // decides whose attention is spent on it. Moin holds every permission
        // as the boss and is deliberately left out of HR's daily traffic.
        // Stock, purchasing and every other module notify unchanged.
        $isHrApproval = str_contains($request->approvable_type, '\\Hrm\\');

        $recipients = User::whereIn('id', $userIds->unique()->all())
            ->where('is_active', true)
            ->when($isHrApproval, fn ($query) => $query->where('receives_hr_notifications', true))
            ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $businessId))
            ->where('id', '!=', $request->requested_by)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PendingApprovalNotification($summary, route('approvals.index')));
        }
    }
}
