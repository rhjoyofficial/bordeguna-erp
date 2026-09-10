<?php

namespace App\Support;

use App\Models\User;
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
            ->with([
                'workflow.stages',
                'requestedBy:id,name',
                'approvable' => fn ($morphTo) => $morphTo->morphWith([
                    StockRequest::class => ['product:id,name,sku', 'warehouse:id,name', 'targetWarehouse:id,name', 'reason:id,name'],
                    PurchaseOrder::class => ['supplier:id,name', 'lines.product:id,name,sku'],
                    SalesOrder::class => ['customer:id,name', 'warehouse:id,name', 'lines.product:id,name,sku'],
                    InventoryAdjustment::class => ['product:id,name,sku', 'warehouse:id,name', 'reason:id,name'],
                    ChangeRequest::class => ['subject', 'requestedBy:id,name'],
                    AttendanceCorrection::class => ['employee:id,employee_code,first_name,last_name', 'punch:id,punched_at'],
                    LeaveRequest::class => ['employee:id,employee_code,first_name,last_name', 'leaveType:id,name'],
                    Movement::class => ['employee:id,employee_code,first_name,last_name'],
                    PayrollRun::class => ['period'],
                    PayrollUnlockRequest::class => ['run.period'],
                    Loan::class => ['employee:id,employee_code,first_name,last_name'],
                    OvertimeAssignment::class => ['employee:id,employee_code,first_name,last_name'],
                    BonusAward::class => ['employee:id,employee_code,first_name,last_name', 'event:id,name'],
                    PayrollAdjustment::class => ['employee:id,employee_code,first_name,last_name', 'period'],
                    SalaryRevision::class => ['employee:id,employee_code,first_name,last_name'],
                    ExpenseClaim::class => ['employee:id,employee_code,first_name,last_name', 'lines.attachments'],
                ]),
            ])
            ->latest()
            ->get()
            ->filter(fn (ApprovalRequest $request) => $this->actionable($request, $user))
            ->values();
    }

    public function countFor(User $user): int
    {
        return $this->pendingActionableFor($user)->count();
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
