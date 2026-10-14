<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ChangeRequest;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayrollUnlockRequest;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\CourierDoorDiscountRequest;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Collection;

/**
 * Resolves the pending approvals a user may currently act on, across every
 * module. Lives at the app level (not Foundation) precisely because it must
 * know all the consuming modules to eager-load and to apply the legacy
 * permission fallback. One source of truth for both the inbox screen and the
 * sidebar badge/nav so they never disagree.
 *
 * Actionable means: the user passes the request's CURRENT workflow stage
 * (canBeActionedBy) — the general, workflow-driven rule — OR, for a legacy
 * request that predates the workflow engine (no workflow attached), they hold
 * the original per-type approve permission.
 */
class ApprovalInbox
{
    /**
     * @return Collection<int, ApprovalRequest>
     */
    public function pendingActionableFor(User $user): Collection
    {
        return ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending)
            ->with($this->approvableGraph())
            ->latest()
            ->get()
            ->filter(fn (ApprovalRequest $request) => $this->actionable($request, $user))
            ->values();
    }

    public function countFor(User $user): int
    {
        return $this->pendingActionableFor($user)->count();
    }

    /**
     * The DECIDED requests this user is entitled to see (Joy, 28 Sep 2026: "after
     * the approval where the[y go], so the authority can see them"). The inbox
     * only ever shows what is still pending; once a request is approved, rejected
     * or handed back it leaves every inbox, and there was nowhere to look it up.
     *
     * Visibility mirrors the inbox rather than showing everything: a person sees a
     * decided request when they are one of its eligible approvers (any stage of
     * its workflow admits them, or the legacy per-type permission), or when they
     * are the one who raised it. That keeps a salary decision from surfacing to an
     * inventory approver just because it is now history.
     *
     * @param  array<int, ApprovalStatus>  $statuses  the states to include
     * @return Collection<int, ApprovalRequest>
     */
    public function decidedVisibleFor(User $user, array $statuses): Collection
    {
        return ApprovalRequest::query()
            ->whereIn('status', $statuses)
            ->with($this->approvableGraph() + ['reviewedBy:id,name'])
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (ApprovalRequest $request) => $this->reviewable($request, $user))
            ->values();
    }

    /**
     * The eager-load graph every approvable needs to render as a card, shared by
     * the pending inbox and the decided history so they can never drift apart.
     *
     * @return array<int|string, mixed>
     */
    private function approvableGraph(): array
    {
        return [
            'workflow.stages',
            'requestedBy:id,name',
            // Rule #22 (14 Oct 2026): the card reads the self-approved mark.
            'decisions',
            'approvable' => fn ($morphTo) => $morphTo->morphWith([
                StockRequest::class => ['product:id,name,sku', 'warehouse:id,name', 'targetWarehouse:id,name', 'reason:id,name'],
                PurchaseOrder::class => ['supplier:id,name', 'lines.product:id,name,sku'],
                SalesOrder::class => ['customer:id,name', 'warehouse:id,name', 'lines.product:id,name,sku'],
                CreditTermsRequest::class => ['lines.customer:id,code,name', 'requestedBy:id,name'],
                CourierDoorDiscountRequest::class => ['consignment.courier:id,name', 'consignment.delivery.customer:id,name'],
                InventoryAdjustment::class => ['product:id,name,sku', 'warehouse:id,name', 'reason:id,name'],
                ChangeRequest::class => ['subject', 'requestedBy:id,name'],
                AttendanceCorrection::class => ['employee:id,employee_code,first_name,last_name', 'punch:id,punched_at'],
                LeaveRequest::class => ['employee:id,employee_code,first_name,last_name', 'leaveType:id,name'],
                Movement::class => ['employee:id,employee_code,first_name,last_name'],
                PayrollRun::class => ['period'],
                PayrollUnlockRequest::class => ['run.period'],
                Loan::class => ['employee:id,employee_code,first_name,last_name,user_id'],
                OvertimeAssignment::class => ['employee:id,employee_code,first_name,last_name,user_id'],
                BonusAward::class => ['employee:id,employee_code,first_name,last_name,user_id', 'event:id,name'],
                PayrollAdjustment::class => ['employee:id,employee_code,first_name,last_name,user_id', 'period'],
                SalaryRevision::class => ['employee:id,employee_code,first_name,last_name,user_id'],
                ExpenseClaim::class => ['employee:id,employee_code,first_name,last_name,user_id', 'lines.attachments'],
                Voucher::class => ['lines.account', 'moneyAccount', 'toMoneyAccount', 'createdBy:id,name'],
            ]),
        ];
    }

    /**
     * May this user SEE this decided request? An eligible approver of its type
     * (any workflow stage admits them, or the legacy permission), or its author.
     */
    private function reviewable(ApprovalRequest $request, User $user): bool
    {
        if ($request->requested_by === $user->id) {
            return true;
        }

        if ($request->workflow_id !== null) {
            return $request->workflow->stages->contains(fn ($stage) => $stage->allows($user));
        }

        return match ($request->approvable_type) {
            (new StockRequest)->getMorphClass() => $user->can('inventory.stock.approve'),
            (new PurchaseOrder)->getMorphClass() => $user->can('purchasing.po.approve'),
            default => false,
        };
    }

    private function actionable(ApprovalRequest $request, User $user): bool
    {
        if ($request->canBeActionedBy($user)) {
            return true;
        }

        // Legacy fallback: a request created before its type had a workflow.
        if ($request->workflow_id === null) {
            return match ($request->approvable_type) {
                (new StockRequest)->getMorphClass() => $user->can('inventory.stock.approve'),
                (new PurchaseOrder)->getMorphClass() => $user->can('purchasing.po.approve'),
                default => false,
            };
        }

        return false;
    }
}
