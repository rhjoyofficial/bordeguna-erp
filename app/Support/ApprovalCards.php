<?php

namespace App\Support;

use App\Modules\Crm\Models\MessageCampaign;
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
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Str;

/**
 * Turns a pending approval into a DECISION CARD.
 *
 * ⚠⚠ Joy's rule (31 Aug 2026): "if I do not get enough details information,
 * why I approve or reject it?" A card therefore carries four things beyond
 * identity: `details` - the labelled FACTS the decision needs (amounts,
 * quantities, what the book holds right now, what saying yes will spend);
 * `reason` - the requester's own words, verbatim, never paraphrased;
 * `lines` - the rows of a document whose lines ARE the decision (a purchase
 * order, a sales order); and `view_url` - the full record, one click away.
 *
 * ⚠ Every case builds through card(), whose `details` parameter is REQUIRED -
 * a new approvable type cannot compile a card without stating its facts, and
 * EveryApprovalReachesSomebodyTest checks the source so a type cannot skip
 * this class entirely.
 *
 * Lives at the app level beside ApprovalInbox and for the same reason: it
 * must know every consuming module.
 */
class ApprovalCards
{
    public function present(ApprovalRequest $approvalRequest): array
    {
        $approvable = $approvalRequest->approvable;

        if ($approvable instanceof StockRequest) {
            $direction = $approvable->type->value === 'transfer'
                ? "Transfer to {$approvable->targetWarehouse?->name}"
                : ucfirst($approvable->direction?->value ?? '');

            return $this->card(
                $approvalRequest,
                type: 'Stock request',
                summary: "{$approvable->product->name} ({$approvable->product->sku}) — {$this->qty($approvable->quantity)} at {$approvable->warehouse->name}",
                details: array_filter([
                    ['label' => 'Product', 'value' => "{$approvable->product->name} ({$approvable->product->sku})"],
                    ['label' => 'Quantity', 'value' => $this->qty($approvable->quantity)],
                    ['label' => 'What', 'value' => $direction],
                    ['label' => 'Warehouse', 'value' => $approvable->warehouse?->name],
                    // What the book holds RIGHT NOW - a decrease of 4 reads
                    // differently against 100 on hand than against 5.
                    ['label' => 'On hand now', 'value' => $this->onHand($approvable->product_id, $approvable->warehouse_id)],
                    $approvable->unit_cost !== null ? ['label' => 'Unit cost', 'value' => $this->money($approvable->unit_cost)] : null,
                    $approvable->reason ? ['label' => 'Reason', 'value' => $approvable->reason->name] : null,
                ]),
                reason: $approvable->note,
                viewUrl: route('inventory.stock-requests.index'),
                approveUrl: route('inventory.stock-requests.approve', $approvable),
                rejectUrl: route('inventory.stock-requests.reject', $approvable),
            );
        }

        if ($approvable instanceof PurchaseOrder) {
            $total = $approvable->lines->sum(fn ($line) => (float) $line->quantity_ordered * (float) $line->unit_cost);

            return $this->card(
                $approvalRequest,
                type: 'Purchase order',
                summary: "{$approvable->po_number} — {$approvable->supplier->name} — ".$this->money($total),
                details: array_filter([
                    ['label' => 'Supplier', 'value' => $approvable->supplier?->name],
                    ['label' => 'Order total', 'value' => $this->money($total)],
                    $approvable->expected_date ? ['label' => 'Expected', 'value' => $approvable->expected_date->toDateString()] : null,
                ]),
                reason: $approvable->note,
                // Saying yes to a PO is saying yes to spending; the lines are
                // the decision and belong on the card, not behind a click.
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => ($line->product?->name ?? 'Product').($line->product?->sku ? " ({$line->product->sku})" : ''),
                    'quantity' => $this->qty($line->quantity_ordered).' × '.$this->money($line->unit_cost),
                    'amount' => $this->money((float) $line->quantity_ordered * (float) $line->unit_cost),
                ])->values()->all(),
                viewUrl: route('purchasing.purchase-orders.show', $approvable),
                approveUrl: route('purchasing.purchase-orders.approve', $approvable),
                rejectUrl: route('purchasing.purchase-orders.reject', $approvable),
            );
        }

        if ($approvable instanceof ChangeRequest) {
            return $this->card(
                $approvalRequest,
                type: 'Change request',
                summary: $approvable->summaryLine(),
                // Old value → new value, field by field: the change IS the fact.
                details: collect($approvable->describedChanges())
                    ->map(fn ($value, $label) => ['label' => $label, 'value' => (string) $value])
                    ->values()->all(),
                reason: $approvable->reason ?? null,
                viewUrl: null,
                approveUrl: route('change-requests.approve', $approvable),
                rejectUrl: route('change-requests.reject', $approvable),
            );
        }

        if ($approvable instanceof AttendanceCorrection) {
            return $this->card(
                $approvalRequest,
                type: 'Attendance correction',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Date', 'value' => (string) $approvable->attendance_date],
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.attendance.corrections.index'),
                approveUrl: route('hrm.attendance.corrections.approve', $approvable),
                rejectUrl: route('hrm.attendance.corrections.reject', $approvable),
            );
        }

        if ($approvable instanceof LeaveRequest) {
            return $this->card(
                $approvalRequest,
                type: 'Leave request',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Leave type', 'value' => $approvable->leaveType?->name],
                    ['label' => 'Dates', 'value' => $approvable->start_date.($approvable->end_date !== $approvable->start_date ? " → {$approvable->end_date}" : '')],
                    ['label' => 'Days', 'value' => $this->qty($approvable->days)],
                    // The single most decision-relevant leave fact: whether the
                    // balance covers it.
                    (float) $approvable->unpaid_days > 0
                        ? ['label' => 'Unpaid days', 'value' => $this->qty($approvable->unpaid_days).' (no balance left for these)']
                        : ['label' => 'Balance', 'value' => 'Covers the whole request'],
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.leave.index'),
                approveUrl: route('hrm.leave.approve', $approvable),
                rejectUrl: route('hrm.leave.reject', $approvable),
            );
        }

        if ($approvable instanceof Movement) {
            return $this->card(
                $approvalRequest,
                type: 'Movement',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Type', 'value' => $approvable->type->label()],
                    ['label' => 'Date', 'value' => (string) $approvable->movement_date],
                    $approvable->place ? ['label' => 'Place', 'value' => $approvable->place] : null,
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.leave.index'),
                approveUrl: route('hrm.movements.approve', $approvable),
                rejectUrl: route('hrm.movements.reject', $approvable),
            );
        }

        if ($approvable instanceof PayrollRun) {
            return $this->card(
                $approvalRequest,
                type: 'Payroll',
                summary: sprintf(
                    '%s v%d — %d employee(s), net %s',
                    $approvable->period?->label(),
                    $approvable->version,
                    $approvable->employee_count,
                    $this->money($approvable->total_net),
                ),
                details: array_filter([
                    ['label' => 'Period', 'value' => $approvable->period?->label()],
                    ['label' => 'Version', 'value' => 'v'.$approvable->version],
                    ['label' => 'Employees', 'value' => (string) $approvable->employee_count],
                    // A blocked employee is exactly what an approver must see
                    // BEFORE approving a payroll, not after.
                    (int) $approvable->blocked_count > 0
                        ? ['label' => 'Blocked', 'value' => $approvable->blocked_count.' employee(s) blocked - review before approving']
                        : null,
                    ['label' => 'Net total', 'value' => $this->money($approvable->total_net)],
                ]),
                reason: null,
                viewUrl: route('hrm.payroll.index'),
                approveUrl: route('hrm.payroll.approve', $approvable),
                rejectUrl: route('hrm.payroll.reject', $approvable),
            );
        }

        if ($approvable instanceof PayrollUnlockRequest) {
            return $this->card(
                $approvalRequest,
                type: 'Payroll unlock',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Period', 'value' => $approvable->run?->period?->label()],
                    ['label' => 'Version', 'value' => 'v'.($approvable->run?->version ?? 0)],
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.payroll.index'),
                approveUrl: route('hrm.payroll.unlock.approve', $approvable),
                rejectUrl: route('hrm.payroll.unlock.reject', $approvable),
            );
        }

        if ($approvable instanceof Loan) {
            return $this->card(
                $approvalRequest,
                type: 'Loan / advance',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Type', 'value' => $approvable->type->label()],
                    ['label' => 'Principal', 'value' => $this->money($approvable->principal)],
                    ['label' => 'Monthly deduction', 'value' => $this->money($approvable->installment_amount)],
                    ['label' => 'Repayment starts', 'value' => $approvable->startsOn()->format('F Y')],
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.loans.index'),
                approveUrl: route('hrm.loans.approve', $approvable),
                rejectUrl: route('hrm.loans.reject', $approvable),
            );
        }

        if ($approvable instanceof OvertimeAssignment) {
            return $this->card(
                $approvalRequest,
                type: 'Overtime',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Date', 'value' => (string) $approvable->work_date],
                    ['label' => 'Hours', 'value' => $this->qty($approvable->hours)],
                    $approvable->is_holiday_work ? ['label' => 'Holiday work', 'value' => 'Yes'] : null,
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.payroll.inputs.index'),
                approveUrl: route('hrm.overtime.approve', $approvable),
                rejectUrl: route('hrm.overtime.reject', $approvable),
            );
        }

        if ($approvable instanceof BonusAward) {
            return $this->card(
                $approvalRequest,
                type: 'Bonus',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Event', 'value' => $approvable->event?->name],
                    ['label' => 'Amount', 'value' => $this->money($approvable->amount)],
                ]),
                reason: $approvable->basis_note,
                viewUrl: route('hrm.payroll.inputs.index'),
                approveUrl: route('hrm.bonus.awards.approve', $approvable),
                rejectUrl: route('hrm.bonus.awards.reject', $approvable),
            );
        }

        if ($approvable instanceof PayrollAdjustment) {
            return $this->card(
                $approvalRequest,
                type: 'Payroll adjustment',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Period', 'value' => $approvable->period?->label()],
                    ['label' => 'Type', 'value' => $approvable->type->label()],
                    ['label' => 'Amount', 'value' => $this->money($approvable->amount)],
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.payroll.inputs.index'),
                approveUrl: route('hrm.adjustments.approve', $approvable),
                rejectUrl: route('hrm.adjustments.reject', $approvable),
            );
        }

        if ($approvable instanceof SalaryRevision) {
            return $this->card(
                $approvalRequest,
                type: 'Salary revision',
                summary: $approvable->summaryLine(),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Effective from', 'value' => (string) $approvable->effective_from],
                    // What is being asked for, in money, before anyone opens it.
                    ['label' => 'Proposed gross (fixed lines)', 'value' => $this->money($approvable->proposedTotal())],
                    (float) $approvable->arrear_amount > 0
                        ? ['label' => 'Arrears', 'value' => $this->money($approvable->arrear_amount).' (reaches back over paid months)']
                        : null,
                ]),
                reason: $approvable->reason,
                // The salary page shows the CURRENT structure beside which this
                // proposal can be judged.
                viewUrl: $approvable->employee_id ? route('hrm.salary.show', $approvable->employee_id) : null,
                approveUrl: route('hrm.revisions.approve', $approvable),
                rejectUrl: route('hrm.revisions.reject', $approvable),
            );
        }

        if ($approvable instanceof ExpenseClaim) {
            return $this->card(
                $approvalRequest,
                type: 'Expense claim',
                summary: "{$approvable->claim_number} — {$approvable->purpose} — ".$this->money($approvable->amount),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Amount', 'value' => $this->money($approvable->amount)],
                    ['label' => 'Spent on', 'value' => $approvable->spent_on?->toDateString()],
                    // An approver deciding on somebody's own money should not
                    // have to open the claim to learn whether it came with proof.
                    ['label' => 'Receipts', 'value' => $approvable->attachments->count() === 0
                        ? 'NONE attached'
                        : $approvable->attachments->count().' attached'],
                ]),
                reason: $approvable->purpose,
                viewUrl: route('hrm.expense-claims.index'),
                approveUrl: route('hrm.expense-claims.approve', $approvable),
                rejectUrl: route('hrm.expense-claims.reject', $approvable),
            );
        }

        if ($approvable instanceof SalesOrder) {
            $total = $approvable->lines->sum(fn ($line) => (float) $line->quantity_ordered * (float) $line->unit_price);

            return $this->card(
                $approvalRequest,
                type: 'Sales order',
                summary: "{$approvable->order_number} — {$approvable->customer?->name} — ".$this->money($total),
                details: array_filter([
                    ['label' => 'Customer', 'value' => $approvable->customer?->name],
                    ['label' => 'Warehouse', 'value' => $approvable->warehouse?->name],
                    ['label' => 'Order total', 'value' => $this->money($total)],
                    $approvable->expected_date ? ['label' => 'Expected', 'value' => (string) $approvable->expected_date] : null,
                ]),
                reason: $approvable->note,
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => ($line->product?->name ?? 'Product').($line->product?->sku ? " ({$line->product->sku})" : ''),
                    'quantity' => $this->qty($line->quantity_ordered).' × '.$this->money($line->unit_price),
                    'amount' => $this->money((float) $line->quantity_ordered * (float) $line->unit_price),
                ])->values()->all(),
                viewUrl: route('sales.orders.show', $approvable),
                approveUrl: route('sales.orders.approve', $approvable),
                rejectUrl: route('sales.orders.reject', $approvable),
            );
        }

        if ($approvable instanceof InventoryAdjustment) {
            return $this->card(
                $approvalRequest,
                type: 'Inventory adjustment',
                summary: sprintf(
                    '%s %s of %s at %s',
                    ucfirst((string) ($approvable->direction?->value ?? '')),
                    $this->qty($approvable->quantity),
                    $approvable->product?->name,
                    $approvable->warehouse?->name,
                ),
                details: array_filter([
                    ['label' => 'Correction type', 'value' => $approvable->type->label()],
                    ['label' => 'Product', 'value' => ($approvable->product?->name ?? '').($approvable->product?->sku ? " ({$approvable->product->sku})" : '')],
                    ['label' => 'Warehouse', 'value' => $approvable->warehouse?->name],
                    ['label' => 'Quantity', 'value' => ucfirst((string) ($approvable->direction?->value ?? '')).' '.$this->qty($approvable->quantity)],
                    // What saying yes is WORTH, and what the book holds now -
                    // the two numbers the decision actually turns on.
                    ['label' => 'Estimated value', 'value' => $this->money($approvable->estimated_value)],
                    ['label' => 'On hand now', 'value' => $this->onHand($approvable->product_id, $approvable->warehouse_id)],
                    $approvable->unit_cost !== null ? ['label' => 'Unit cost', 'value' => $this->money($approvable->unit_cost)] : null,
                    $approvable->new_unit_cost !== null ? ['label' => 'New unit cost', 'value' => $this->money($approvable->new_unit_cost)] : null,
                    $approvable->effective_date ? ['label' => 'Effective', 'value' => $approvable->effective_date->toDateString()] : null,
                    $approvable->reason ? ['label' => 'Reason', 'value' => $approvable->reason->name] : null,
                ]),
                reason: $approvable->note,
                viewUrl: route('inventory.stock.index'),
                approveUrl: route('inventory.stock.adjustments.approve', $approvable),
                rejectUrl: route('inventory.stock.adjustments.reject', $approvable),
            );
        }

        if ($approvable instanceof MessageCampaign) {
            return $this->card(
                $approvalRequest,
                type: 'Message campaign',
                summary: sprintf(
                    '%s — %s to %d recipient(s), about %s',
                    $approvable->name,
                    strtoupper((string) $approvable->channel),
                    $approvable->estimated_recipients,
                    $this->money($approvable->estimated_cost),
                ),
                details: array_filter([
                    ['label' => 'Channel', 'value' => strtoupper((string) $approvable->channel)],
                    ['label' => 'Audience', 'value' => (string) $approvable->audience_type],
                    ['label' => 'Recipients', 'value' => (string) $approvable->estimated_recipients],
                    ['label' => 'Message parts', 'value' => (string) $approvable->estimated_parts],
                    // ⚠⚠ The only approval where saying yes spends money
                    // DIRECTLY.
                    ['label' => 'Estimated cost', 'value' => $this->money($approvable->estimated_cost)],
                    $approvable->subject ? ['label' => 'Subject', 'value' => $approvable->subject] : null,
                ]),
                // The words about to reach every one of those phones ARE the
                // decision.
                reason: Str::limit((string) $approvable->body, 300),
                viewUrl: null,
                approveUrl: route('crm.campaigns.approve', $approvable),
                rejectUrl: route('crm.campaigns.reject', $approvable),
            );
        }

        // ⚠⚠ THE SILENT ROW. Anything reaching here is listed with no approve or
        // reject link at all: visible, unactionable, and it looks like the
        // approver simply has nothing to do. EveryApprovalReachesSomebodyTest
        // exists so a new Approvable model cannot arrive here unnoticed.
        return $this->card(
            $approvalRequest,
            type: class_basename($approvable),
            summary: "#{$approvable->id}",
            details: [],
            reason: null,
            viewUrl: null,
            approveUrl: null,
            rejectUrl: null,
        );
    }

    /**
     * ⚠ `details` is REQUIRED on purpose: a new approvable type cannot build a
     * card without stating the facts its decision needs.
     *
     * @param  list<array{label: string, value: string}|null>  $details
     * @param  list<array{description: string, quantity: string, amount: string}>  $lines
     */
    private function card(
        ApprovalRequest $approvalRequest,
        string $type,
        string $summary,
        array $details,
        ?string $reason,
        ?string $viewUrl,
        ?string $approveUrl,
        ?string $rejectUrl,
        array $lines = [],
    ): array {
        return [
            'id' => $approvalRequest->id,
            'type' => $type,
            'summary' => $summary,
            // array_filter above may drop nulls and leave gaps; the screen
            // wants a clean list.
            'details' => array_values(array_filter($details)),
            'reason' => ($reason === null || trim($reason) === '') ? null : $reason,
            'lines' => $lines,
            'view_url' => $viewUrl,
            'approve_url' => $approveUrl,
            'reject_url' => $rejectUrl,
            'requested_by' => $approvalRequest->requestedBy?->name,
            'requested_at' => $approvalRequest->created_at->diffForHumans(),
            'stage' => $approvalRequest->totalStages() > 1 ? $approvalRequest->currentStage()?->name : null,
        ];
    }

    /** `20.0000` → `20`, `20.5000` → `20.5`. Trims, never rounds. */
    private function qty(mixed $value): string
    {
        $string = (string) $value;

        if (! str_contains($string, '.')) {
            return $string;
        }

        $trimmed = rtrim(rtrim($string, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    /** What the book holds right now for this product in this warehouse. */
    private function onHand(?int $productId, ?int $warehouseId): string
    {
        if ($productId === null || $warehouseId === null) {
            return '0';
        }

        return $this->qty((string) StockBatch::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', StockBatchStatus::Active)
            ->sum('current_quantity'));
    }
}
