<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ChangeRequest;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayrollUnlockRequest;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\ApprovalInbox;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The common approval inbox: one page listing every pending request the current
 * user may act on, across modules. Actionability (and the stage-aware "who")
 * comes from ApprovalInbox so this screen and the sidebar badge never disagree.
 * Each row links back to the owning approve/reject route — the two legacy types
 * to their module routes, change requests to the generic workflow endpoint — so
 * a new consumer only adds a case to present().
 */
class ApprovalInboxController extends Controller
{
    public function index(Request $request, ApprovalInbox $inbox): Response
    {
        $user = $request->user();
        $actionable = $inbox->pendingActionableFor($user);

        // Legacy approvers may always open the inbox; anyone else only once they
        // actually have something to act on (mirrors canReviewApprovals).
        $canReviewLegacy = $user->can('inventory.stock.approve') || $user->can('purchasing.po.approve');
        abort_unless($actionable->isNotEmpty() || $canReviewLegacy, 403);

        $requests = $actionable->map(fn (ApprovalRequest $approvalRequest) => $this->present($approvalRequest));

        return Inertia::render('approvals/index', [
            'requests' => $requests->values(),
            'counts' => $requests->groupBy('type')
                ->map(fn ($rows, $type) => ['label' => $type, 'count' => $rows->count()])
                ->values(),
        ]);
    }

    private function present(ApprovalRequest $approvalRequest): array
    {
        $approvable = $approvalRequest->approvable;

        $shared = [
            'id' => $approvalRequest->id,
            'requested_by' => $approvalRequest->requestedBy?->name,
            'requested_at' => $approvalRequest->created_at->diffForHumans(),
            // For multi-stage requests, which stage it's waiting on.
            'stage' => $approvalRequest->totalStages() > 1 ? $approvalRequest->currentStage()?->name : null,
        ];

        if ($approvable instanceof StockRequest) {
            $direction = $approvable->type->value === 'transfer'
                ? "→ {$approvable->targetWarehouse?->name}"
                : ucfirst($approvable->direction?->value ?? '');

            return [
                ...$shared,
                'type' => 'Stock request',
                'summary' => "{$approvable->product->name} ({$approvable->product->sku}) — {$approvable->quantity} at {$approvable->warehouse->name} {$direction}",
                'approve_url' => route('inventory.stock-requests.approve', $approvable),
                'reject_url' => route('inventory.stock-requests.reject', $approvable),
            ];
        }

        if ($approvable instanceof PurchaseOrder) {
            $total = $approvable->lines->sum(fn ($line) => (float) $line->quantity_ordered * (float) $line->unit_cost);

            return [
                ...$shared,
                'type' => 'Purchase order',
                'summary' => "{$approvable->po_number} — {$approvable->supplier->name} — {$total}",
                'approve_url' => route('purchasing.purchase-orders.approve', $approvable),
                'reject_url' => route('purchasing.purchase-orders.reject', $approvable),
            ];
        }

        if ($approvable instanceof ChangeRequest) {
            $detail = collect($approvable->describedChanges())->map(fn ($value, $label) => "{$label}: {$value}")->implode(', ');

            return [
                ...$shared,
                'type' => 'Change request',
                'summary' => $approvable->summaryLine().($detail !== '' ? " ({$detail})" : ''),
                'approve_url' => route('change-requests.approve', $approvable),
                'reject_url' => route('change-requests.reject', $approvable),
            ];
        }

        if ($approvable instanceof AttendanceCorrection) {
            return [
                ...$shared,
                'type' => 'Attendance correction',
                'summary' => $approvable->summaryLine().' — '.$approvable->reason,
                'approve_url' => route('hrm.attendance.corrections.approve', $approvable),
                'reject_url' => route('hrm.attendance.corrections.reject', $approvable),
            ];
        }

        if ($approvable instanceof LeaveRequest) {
            $unpaid = (float) $approvable->unpaid_days > 0
                ? sprintf(' — %s day(s) unpaid, no balance left', $approvable->unpaid_days)
                : '';

            return [
                ...$shared,
                'type' => 'Leave request',
                'summary' => $approvable->summaryLine().$unpaid,
                'approve_url' => route('hrm.leave.approve', $approvable),
                'reject_url' => route('hrm.leave.reject', $approvable),
            ];
        }

        if ($approvable instanceof Movement) {
            return [
                ...$shared,
                'type' => 'Movement',
                'summary' => $approvable->summaryLine().' — '.$approvable->reason,
                'approve_url' => route('hrm.movements.approve', $approvable),
                'reject_url' => route('hrm.movements.reject', $approvable),
            ];
        }

        if ($approvable instanceof PayrollRun) {
            return [
                ...$shared,
                'type' => 'Payroll',
                'summary' => sprintf(
                    '%s v%d — %d employee(s), net %s',
                    $approvable->period?->label(),
                    $approvable->version,
                    $approvable->employee_count,
                    number_format((float) $approvable->total_net, 2),
                ),
                'approve_url' => route('hrm.payroll.approve', $approvable),
                'reject_url' => route('hrm.payroll.reject', $approvable),
            ];
        }

        if ($approvable instanceof PayrollUnlockRequest) {
            return [
                ...$shared,
                'type' => 'Payroll unlock',
                'summary' => $approvable->summaryLine(),
                'approve_url' => route('hrm.payroll.unlock.approve', $approvable),
                'reject_url' => route('hrm.payroll.unlock.reject', $approvable),
            ];
        }

        if ($approvable instanceof OvertimeAssignment) {
            return [
                ...$shared,
                'type' => 'Overtime',
                'summary' => $approvable->summaryLine().' — '.$approvable->reason,
                'approve_url' => route('hrm.overtime.approve', $approvable),
                'reject_url' => route('hrm.overtime.reject', $approvable),
            ];
        }

        if ($approvable instanceof BonusAward) {
            return [
                ...$shared,
                'type' => 'Bonus',
                'summary' => $approvable->summaryLine().($approvable->basis_note ? " ({$approvable->basis_note})" : ''),
                'approve_url' => route('hrm.bonus.awards.approve', $approvable),
                'reject_url' => route('hrm.bonus.awards.reject', $approvable),
            ];
        }

        if ($approvable instanceof PayrollAdjustment) {
            return [
                ...$shared,
                'type' => 'Payroll adjustment',
                'summary' => $approvable->summaryLine().' — '.$approvable->reason,
                'approve_url' => route('hrm.adjustments.approve', $approvable),
                'reject_url' => route('hrm.adjustments.reject', $approvable),
            ];
        }

        if ($approvable instanceof SalaryRevision) {
            return [
                ...$shared,
                'type' => 'Salary revision',
                'summary' => $approvable->summaryLine().' — '.$approvable->reason,
                'approve_url' => route('hrm.revisions.approve', $approvable),
                'reject_url' => route('hrm.revisions.reject', $approvable),
            ];
        }

        return [...$shared, 'type' => class_basename($approvable), 'summary' => "#{$approvable->id}", 'approve_url' => null, 'reject_url' => null];
    }
}
