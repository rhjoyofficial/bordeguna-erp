<?php

namespace App\Support;

use App\Modules\Accounting\Models\LoanAmendment;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanWriteOff;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\Loans\LoanMath;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyPosition;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ChangeRequest;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Hrm\Enums\PayrollStatus;
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
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\CourierDoorDiscountRequest;
use App\Modules\Sales\Models\CreditTermsRequest;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
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
 * ⚠⚠ `view_url` must carry the FILTERS its target screen needs (Joy, 1 Sep
 * 2026: "when I clicked on the Open record, shows nothing"). Every one of
 * these screens filters something - a payroll screen defaults to TODAY'S
 * month, a leave screen to today's year - so a bare index link silently shows
 * the WRONG period and reads as an empty page. The link must name the record's
 * own period, employee or warehouse; ApprovalCardLinksTest follows the links
 * and insists the record is on the page that comes back.
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
                viewUrl: route('inventory.stock-requests.index', ['status' => 'pending']),
                approveUrl: route('inventory.stock-requests.approve', $approvable),
                rejectUrl: route('inventory.stock-requests.reject', $approvable),
            );
        }

        // ⭐ Landed cost (Phase 1, 17 Oct 2026): saying yes capitalises part of
        // the cost onto stock and expenses the rest; the lines ARE the decision.
        if ($approvable instanceof LandedCost) {
            $total = Money::ofMinor((int) $approvable->total_paisa);
            $capitalised = Money::sum($approvable->lines->filter->isCapitalised()->map->amount());
            $overrides = $approvable->lines->filter->overridesDefault();

            return $this->card(
                $approvalRequest,
                type: 'Landed cost',
                summary: "{$approvable->number}, goods receipt #{$approvable->goods_receipt_id}, Tk {$total->format()}",
                details: array_filter([
                    ['label' => 'Goods receipt', 'value' => '#'.$approvable->goods_receipt_id.($approvable->goodsReceipt?->purchaseOrder?->po_number ? ' on '.$approvable->goodsReceipt->purchaseOrder->po_number : '')],
                    ['label' => 'Incurred on', 'value' => $approvable->incurred_on?->toDateString()],
                    ['label' => 'Total', 'value' => 'Tk '.$total->format()],
                    ['label' => 'Added to stock value', 'value' => 'Tk '.$capitalised->format()],
                    ['label' => 'Expensed', 'value' => 'Tk '.Money::ofMinor($total->minor - $capitalised->minor)->format()],
                    $overrides->isNotEmpty() ? ['label' => 'Overrides the default treatment', 'value' => $overrides->map(fn ($line) => $line->cost_type->label().': '.$line->override_reason)->implode('; ')] : null,
                ]),
                reason: $approvable->note,
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => $line->cost_type->label().' ('.$line->treatment->label().($line->isCapitalised() ? ', '.$line->allocation_method->label() : '').')'
                        .($line->supplier ? ', owed to '.$line->supplier->name : '').($line->moneyAccount ? ', paid from '.$line->moneyAccount->name : ''),
                    'quantity' => $line->description ?? '',
                    'amount' => 'Tk '.$line->amount()->format(),
                ])->values()->all(),
                viewUrl: route('purchasing.landed-costs.index', ['receipt' => $approvable->goods_receipt_id]),
                approveUrl: route('purchasing.landed-costs.approve', $approvable),
                rejectUrl: route('purchasing.landed-costs.reject', $approvable),
            );
        }

        if ($approvable instanceof PurchaseOrder) {
            // 17 Oct 2026: the stored line totals, the figure the order itself shows.
            $total = Money::sum($approvable->lines->map(fn ($line) => $line->lineTotal()))->toTaka();

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
                    'amount' => $this->money($line->lineTotal()->toTaka()),
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
                    ['label' => 'Dates', 'value' => $this->dateRange($approvable->start_date, $approvable->end_date)],
                    ['label' => 'Days', 'value' => $this->qty($approvable->days)],
                    // The single most decision-relevant leave fact: whether the
                    // balance covers it.
                    (float) $approvable->unpaid_days > 0
                        ? ['label' => 'Unpaid days', 'value' => $this->qty($approvable->unpaid_days).' (no balance left for these)']
                        : ['label' => 'Balance', 'value' => 'Covers the whole request'],
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.leave.index', array_filter([
                    'employee_id' => $approvable->employee_id,
                    'year' => $this->yearOf($approvable->start_date),
                ])),
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
                viewUrl: route('hrm.leave.index', array_filter([
                    'employee_id' => $approvable->employee_id,
                    'year' => $this->yearOf($approvable->movement_date),
                ])),
                approveUrl: route('hrm.movements.approve', $approvable),
                rejectUrl: route('hrm.movements.reject', $approvable),
            );
        }

        if ($approvable instanceof PayrollRun) {
            // ⚠ A request left behind on a run that was cancelled or reopened
            // before withdrawal existed: no Approve button, only Reject to
            // clear it. TransitionPayrollRun refuses the approval anyway.
            $stale = $approvable->status !== PayrollStatus::Reviewed;

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
                    $stale
                        ? ['label' => 'No longer awaiting approval', 'value' => 'This version is '.$approvable->status->label().'. Reject to clear it.']
                        : null,
                ]),
                reason: null,
                viewUrl: route('hrm.payroll.index', array_filter([
                    'year' => $approvable->period?->year,
                    'month' => $approvable->period?->month,
                ])),
                approveUrl: $stale ? null : route('hrm.payroll.approve', $approvable),
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
                viewUrl: route('hrm.payroll.index', array_filter([
                    'year' => $approvable->run?->period?->year,
                    'month' => $approvable->run?->period?->month,
                ])),
                approveUrl: route('hrm.payroll.unlock.approve', $approvable),
                rejectUrl: route('hrm.payroll.unlock.reject', $approvable),
            );
        }

        if ($approvable instanceof Loan) {
            // ⚠⚠ THE FINAL APPROVAL DISBURSES, AND DISBURSING NEEDS THE ACCOUNT
            // THE MONEY LEFT. This card can post nothing but "approve", so the
            // last stage used to come back as a validation error the inbox never
            // showed: the button looked dead. The loans screen asks the account
            // and the day, so the final stage is decided there, and the card
            // says so instead of offering a button that cannot work.
            $decidedOnRecord = $approvalRequest->remainingStages() <= 1;

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
                    $decidedOnRecord
                        ? ['label' => 'To approve', 'value' => 'Open the record. Approving pays the money out, so it asks which account it leaves and on what day.']
                        : null,
                ]),
                reason: $approvable->reason,
                viewUrl: route('hrm.loans.index', array_filter(['employee_id' => $approvable->employee_id])),
                approveUrl: $decidedOnRecord ? null : route('hrm.loans.approve', $approvable),
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
                viewUrl: route('hrm.payroll.inputs.index', array_filter([
                    'employee_id' => $approvable->employee_id,
                ])),
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
                viewUrl: route('hrm.payroll.inputs.index', array_filter([
                    'payroll_period_id' => $approvable->event?->payroll_period_id,
                ])),
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
                viewUrl: route('hrm.payroll.inputs.index', array_filter([
                    'employee_id' => $approvable->employee_id,
                    'payroll_period_id' => $approvable->payroll_period_id,
                ])),
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

        // ⭐ A payment above the approval limit, posted by its own preparer
        // (finance decision B13, 29 Sep 2026). Approving POSTS it.
        if ($approvable instanceof Voucher) {
            $from = $approvable->moneyAccount?->label();
            $to = $approvable->toMoneyAccount?->label();
            $heads = $approvable->lines->map(fn ($line) => $line->account?->label())->filter()->unique()->values();

            return $this->card(
                $approvalRequest,
                type: $approvable->type->label(),
                summary: "{$approvable->title()} — ".$approvable->total()->format(),
                details: array_filter([
                    ['label' => 'Amount', 'value' => $approvable->total()->format()],
                    ['label' => 'Date', 'value' => $approvable->voucher_date?->toDateString()],
                    $from === null ? null : ['label' => $approvable->type->value === 'contra' ? 'Moved from' : 'Paid from', 'value' => $from],
                    $to === null ? null : ['label' => 'Moved to', 'value' => $to],
                    $approvable->counterparty ? ['label' => 'Paid to', 'value' => $approvable->counterparty] : null,
                    ['label' => 'Prepared by', 'value' => $approvable->createdBy?->name],
                    $heads->isEmpty() ? null : ['label' => 'Books', 'value' => 'Approving posts it: Dr '.$heads->implode(', ').($from ? " / Cr {$from}" : '')],
                    $approvable->narration ? ['label' => 'Narration', 'value' => $approvable->narration] : null,
                ]),
                reason: $approvable->narration,
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => trim(($line->account?->label() ?? '').($line->narration ? ' — '.$line->narration : '')),
                    'quantity' => '',
                    'amount' => $line->amount()->format(),
                ])->values()->all(),
                viewUrl: route('accounting.vouchers.show', $approvable),
                approveUrl: route('accounting.vouchers.approve', $approvable),
                // A refusal hands it back to its preparer as a draft: a wrong
                // figure is corrected and sent again, never retyped.
                rejectUrl: route('accounting.vouchers.send-back', $approvable),
                sendBackUrl: route('accounting.vouchers.send-back', $approvable),
            );
        }

        // ⭐ A set-off (decision D-F, 18 Oct 2026): one firm is our supplier and
        // our customer; approving POSTS it and clears the named bills and
        // invoices by the same amount on each side.
        if ($approvable instanceof SetOff) {
            $approvable->loadMissing(['supplier', 'customer', 'createdBy', 'lines.bill', 'lines.invoice']);
            $position = $approvable->supplier !== null && $approvable->customer !== null
                ? PartyPosition::of($approvable->supplier, $approvable->customer)
                : null;

            return $this->card(
                $approvalRequest,
                type: 'Set-off',
                summary: "{$approvable->number} - {$approvable->supplier?->name} - Tk {$approvable->money()->format()}",
                details: array_filter([
                    ['label' => 'Party', 'value' => trim(($approvable->supplier?->name ?? '').' (supplier) and '.($approvable->customer?->name ?? '').' (customer)')],
                    ['label' => 'Amount', 'value' => 'Tk '.$approvable->money()->format().' on each side'],
                    ['label' => 'Date', 'value' => $approvable->set_off_on?->toDateString()],
                    $position === null ? null : ['label' => 'Open now', 'value' => "We owe them Tk {$position->weOwe->format()}; they owe us Tk {$position->theyOwe->format()}"],
                    ['label' => 'Raised by', 'value' => $approvable->createdBy?->name],
                    ['label' => 'Books', 'value' => 'Approving posts it: Dr 2110 Accounts Payable (supplier) / Cr 1140 Accounts Receivable (customer)'],
                ]),
                reason: $approvable->reason,
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => $line->bill !== null ? "Bill {$line->bill->bill_number} (we owe)" : "Invoice {$line->invoice?->invoice_number} (they owe)",
                    'quantity' => '',
                    'amount' => $line->money()->format(),
                ])->values()->all(),
                viewUrl: route('accounting.set-offs.show', $approvable),
                approveUrl: route('accounting.set-offs.approve', $approvable),
                rejectUrl: route('accounting.set-offs.reject', $approvable),
            );
        }

        // ⭐ The Loan Register (loans plan v2, phase 2): the Owner approves the
        // agreement; no money moves until somebody records it on the loan page.
        if ($approvable instanceof LoanContract) {
            $approvable->loadMissing(['counterparty', 'principalAccount']);
            $party = $approvable->counterparty;

            return $this->card(
                $approvalRequest,
                type: 'Loan agreement',
                summary: "{$approvable->loan_number} - {$approvable->direction->label()} - {$party?->legal_name} - {$approvable->principal()->format()}",
                details: array_filter([
                    ['label' => 'Direction', 'value' => $approvable->direction->label()],
                    ['label' => 'Counterparty', 'value' => trim(($party?->legal_name ?? '').($party?->related_party ? ' (related party'.($party->relationship ? ": {$party->relationship}" : '').')' : ''))],
                    ['label' => 'Principal', 'value' => $approvable->principal()->format()],
                    ['label' => 'Interest', 'value' => $approvable->rateE4() === 0
                        ? 'Interest-free: '.$approvable->interest_free_reason
                        : LoanMath::rateString($approvable->rateE4()).'% a year, '.$approvable->day_count->label()],
                    ['label' => 'Term', 'value' => $this->dateRange($approvable->start_date, $approvable->maturity_date)],
                    ['label' => 'Repayment', 'value' => $approvable->repayment_type->label().', '.$approvable->frequency->label().($approvable->grace_months > 0 ? ", {$approvable->grace_months} months grace" : '')],
                    ['label' => 'Books', 'value' => 'Approving records the agreement only. The principal goes on '.$approvable->principalAccount?->label().' when the money is recorded.'],
                    $approvable->principalAccount?->system_slug === 'owner_loans' ? ['label' => 'Note', 'value' => 'Loans from Owners: only for a documented owner loan agreed with the accountant; owner money is normally Owner\'s Capital (3100).'] : null,
                    $approvable->collateral ? ['label' => 'Collateral', 'value' => $approvable->collateral] : null,
                ]),
                reason: $approvable->purpose,
                viewUrl: route('accounting.loans.show', $approvable),
                approveUrl: route('accounting.loans.approve', $approvable),
                // A refusal hands it back as a draft, to be corrected, never retyped.
                rejectUrl: route('accounting.loans.send-back', $approvable),
                sendBackUrl: route('accounting.loans.send-back', $approvable),
            );
        }

        if ($approvable instanceof LoanAmendment) {
            $loan = $approvable->loan()->with('counterparty')->first();
            $terms = $approvable->terms;
            $position = $loan === null ? null : LoanPosition::of($loan);

            return $this->card(
                $approvalRequest,
                type: 'Loan restructure',
                summary: "{$loan?->loan_number} - {$loan?->counterparty?->legal_name} - new terms from ".$approvable->effective_from->format('j M Y'),
                details: array_filter([
                    ['label' => 'Loan', 'value' => "{$loan?->loan_number} ({$loan?->direction->label()}) with {$loan?->counterparty?->legal_name}"],
                    $position === null ? null : ['label' => 'Principal owed now', 'value' => Money::ofMinor(max(0, $position->principal))->format()],
                    ['label' => 'Rate now / new', 'value' => ($loan === null ? '' : LoanMath::rateString($loan->rateE4()).'%').' / '.($terms['annual_rate'] ?? '0').'%'],
                    ['label' => 'Maturity now / new', 'value' => ($loan?->maturity_date?->format('j M Y') ?? '').' / '.Carbon::parse((string) ($terms['maturity_date'] ?? 'today'))->format('j M Y')],
                    ['label' => 'New schedule', 'value' => ($terms['repayment_type'] ?? '').', '.($terms['frequency'] ?? '').(($terms['grace_months'] ?? 0) > 0 ? ", {$terms['grace_months']} months grace" : '')],
                    ['label' => 'Effective from', 'value' => $approvable->effective_from->format('j M Y')],
                    ['label' => 'History', 'value' => 'The current terms and schedule are kept as version '.$approvable->from_version.'; posted accruals are adjusted, never rewritten.'],
                ]),
                reason: $approvable->reason,
                viewUrl: $loan === null ? null : route('accounting.loans.show', $loan),
                approveUrl: route('accounting.loans.amendments.approve', $approvable),
                rejectUrl: route('accounting.loans.amendments.reject', $approvable),
            );
        }

        if ($approvable instanceof LoanWriteOff) {
            $loan = $approvable->loan()->with('counterparty')->first();
            $position = $loan === null ? null : LoanPosition::of($loan);
            $books = $approvable->isPrincipal()
                ? 'Approving posts it: Dr 6406 Bad Debts Written Off / Cr the loan principal head'
                : ($approvable->amount_minor > 0 ? 'Approving reverses the accrued interest' : 'Approving forgoes interest on the schedule; no journal').($approvable->forgo_minor > 0 && $approvable->amount_minor > 0 ? ' and forgoes the rest on the schedule' : '');

            return $this->card(
                $approvalRequest,
                type: $approvable->label(),
                summary: "{$loan?->loan_number} - {$loan?->counterparty?->legal_name} - ".Money::ofMinor((int) $approvable->amount_minor + (int) $approvable->forgo_minor)->format(),
                details: array_filter([
                    ['label' => 'Loan', 'value' => "{$loan?->loan_number} ({$loan?->direction->label()}) with {$loan?->counterparty?->legal_name}"],
                    ['label' => 'What', 'value' => $approvable->label()],
                    $approvable->amount_minor > 0 ? ['label' => $approvable->isPrincipal() ? 'Principal written off' : 'Accrued interest waived', 'value' => $approvable->amount()->format()] : null,
                    $approvable->forgo_minor > 0 ? ['label' => 'Interest forgone (not yet accrued)', 'value' => Money::ofMinor((int) $approvable->forgo_minor)->format()] : null,
                    $position === null ? null : ['label' => 'Owed now', 'value' => 'Principal '.Money::ofMinor(max(0, $position->principal))->format().', interest accrued '.Money::ofMinor($position->accrued)->format()],
                    ['label' => 'Effective', 'value' => $approvable->effective_date->format('j M Y')],
                    ['label' => 'Evidence', 'value' => $approvable->attachments()->count().' file(s) attached'],
                    ['label' => 'Books', 'value' => $books],
                ]),
                reason: $approvable->reason,
                viewUrl: $loan === null ? null : route('accounting.loans.show', $loan),
                approveUrl: route('accounting.loans.write-offs.approve', $approvable),
                rejectUrl: route('accounting.loans.write-offs.reject', $approvable),
            );
        }

        if ($approvable instanceof ExpenseClaim) {
            $lines = $approvable->payableLines();
            $heads = $lines
                ->map(fn ($line) => $line->category()['account'] ?? null)
                ->filter()->unique()->values();
            $exceptions = $lines
                ->filter(fn ($line) => ($line->category()['is_exception'] ?? false) === true)
                ->map(fn ($line) => $line->category()['name'])
                ->unique()->values();
            $missing = $lines->filter(fn ($line) => $line->attachments->isEmpty())->count();

            return $this->card(
                $approvalRequest,
                type: 'Expense claim',
                summary: "{$approvable->claim_number} — {$approvable->displayName()} — ".$this->money($approvable->amount),
                details: array_filter([
                    ['label' => 'Employee', 'value' => $approvable->employee?->fullName()],
                    ['label' => 'Claim total', 'value' => $this->money($approvable->amount)
                        .($lines->count() > 1 ? " across {$lines->count()} spends" : '')],
                    ['label' => 'Spent on', 'value' => $approvable->spent_on?->toDateString()],
                    // ⚠ Approving is now a POSTING, not a promise: the last yes
                    // writes the journal. An approver is therefore told which
                    // accounts it lands in, and when any line sits in a
                    // catch-all category the accountant flagged for scrutiny.
                    $heads->isEmpty() ? null : ['label' => 'Books', 'value' => 'Approving posts Dr '
                        .$heads->implode(', ').' / Cr Employee Reimbursements Payable. The cash moves later, when accounts pays it back.'],
                    $exceptions->isEmpty() ? null : ['label' => 'Look twice', 'value' => $exceptions->implode(', ')
                        .' — a catch-all category: check what this really was'],
                    // An approver deciding on somebody's own money should not
                    // have to open the claim to learn whether it came with proof.
                    ['label' => 'Receipts', 'value' => $missing === 0
                        ? 'every spend has one'
                        : "{$missing} of {$lines->count()} spends have NO receipt"],
                ]),
                reason: $approvable->displayName(),
                // ⚠⚠ THE LINES ARE THE DECISION. A market trip approved from a
                // single total is a total nobody checked; the approver needs the
                // rickshaw, the courier and the tea separately, because that is
                // what the receipts in their hand look like.
                lines: $lines->map(fn ($line) => [
                    'description' => trim(($line->category()['name'] ?? 'Expense')
                        .($line->description ? ' — '.$line->description : '')),
                    'quantity' => $line->spent_on?->toDateString().($line->attachments->isEmpty() ? ' · NO receipt' : ''),
                    'amount' => $this->money($line->amount),
                ])->values()->all(),
                viewUrl: route('hrm.expense-claims.index'),
                approveUrl: route('hrm.expense-claims.approve', $approvable),
                rejectUrl: route('hrm.expense-claims.reject', $approvable),
                // ⚠ One wrong figure used to mean rejecting the claim and
                // making the employee type it all again. This hands it back.
                sendBackUrl: route('hrm.expense-claims.send-back', $approvable),
            );
        }

        if ($approvable instanceof SalesOrder) {
            // ⚠ Net of each line's agreed discount (website slice 2.5a). The
            // approver is deciding whether to commit to what the customer
            // agreed, and a bundle's parts at full price is not that figure.
            // Slice 2.5b: and net of a coupon's discount on the whole order.
            $total = $approvable->lines->sum(fn ($line) => (float) $line->netTotal()->toTaka())
                - (float) $approvable->orderDiscount()->toTaka();

            return $this->card(
                $approvalRequest,
                type: 'Sales order',
                summary: "{$approvable->order_number} — {$approvable->customer?->name} — ".$this->money($total),
                details: array_filter([
                    ['label' => 'Customer', 'value' => $approvable->customer?->name],
                    ['label' => 'Warehouse', 'value' => $approvable->warehouse?->name],
                    $approvable->orderDiscount()->isZero() ? null : ['label' => 'Code '.$approvable->coupon_code, 'value' => '- '.$this->money($approvable->orderDiscount()->toTaka())],
                    ['label' => 'Order total', 'value' => $this->money($total)],
                    $approvable->expected_date ? ['label' => 'Expected', 'value' => (string) $approvable->expected_date] : null,
                ]),
                reason: $approvable->note,
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => ($line->product?->name ?? 'Product').($line->product?->sku ? " ({$line->product->sku})" : '')
                        .($line->bundle_label ? " - {$line->bundle_label}" : ''),
                    'quantity' => $this->qty($line->quantity_ordered).' × '.$this->money($line->unit_price)
                        .($line->discount()->isZero() ? '' : ' less '.$this->money($line->discount()->toTaka())),
                    'amount' => $this->money((float) $line->netTotal()->toTaka()),
                ])->values()->all(),
                viewUrl: route('sales.orders.show', $approvable),
                approveUrl: route('sales.orders.approve', $approvable),
                rejectUrl: route('sales.orders.reject', $approvable),
            );
        }

        if ($approvable instanceof CreditTermsRequest) {
            $before = $approvable->lines->sum(fn ($line) => (float) ($line->current_credit_limit ?? 0));
            $after = $approvable->lines->sum(fn ($line) => (float) ($line->proposed_credit_limit ?? 0));
            $largest = $approvable->lines->max(fn ($line) => (float) ($line->proposed_credit_limit ?? 0) - (float) ($line->current_credit_limit ?? 0));

            return $this->card(
                $approvalRequest,
                type: 'Credit terms',
                summary: "{$approvable->request_number} — {$approvable->line_count} customer(s) — limits {$this->money($before)} to {$this->money($after)}",
                details: array_filter([
                    ['label' => 'Asked by', 'value' => $approvable->requestedBy?->name],
                    ['label' => 'Customers', 'value' => (string) $approvable->line_count],
                    ['label' => 'Total limits before', 'value' => $this->money($before)],
                    ['label' => 'Total limits after', 'value' => $this->money($after)],
                    $largest !== null && $largest > 0 ? ['label' => 'Largest single increase', 'value' => $this->money($largest)] : null,
                    $approvable->source_file ? ['label' => 'File', 'value' => $approvable->source_file] : null,
                ]),
                reason: $approvable->note,
                lines: $approvable->lines->map(fn ($line) => [
                    'description' => trim(($line->customer?->code ? $line->customer->code.' ' : '').($line->customer?->name ?? 'Customer')),
                    'quantity' => 'limit '.($line->current_credit_limit === null ? 'none' : $this->money($line->current_credit_limit)).' -> '.($line->proposed_credit_limit === null ? 'none' : $this->money($line->proposed_credit_limit))
                        .', days '.($line->current_credit_days ?? 'default').' -> '.($line->proposed_credit_days ?? 'default'),
                    'amount' => $line->proposed_credit_limit === null ? '' : $this->money($line->proposed_credit_limit),
                ])->values()->all(),
                viewUrl: route('sales.credit-terms.show', $approvable),
                approveUrl: route('sales.credit-terms.approve', $approvable),
                rejectUrl: route('sales.credit-terms.reject', $approvable),
            );
        }

        // ⭐ Courier hub slice 8: a door discount above the confirming
        // person's cap. Approving records the courier's whole answer.
        if ($approvable instanceof CourierDoorDiscountRequest) {
            $consignment = $approvable->consignment;

            return $this->card(
                $approvalRequest,
                type: 'Door discount',
                summary: "{$this->money($approvable->discount_amount)} off at the door on {$consignment?->consignment_number}",
                details: [
                    ['label' => 'Courier', 'value' => $consignment?->courier?->name],
                    ['label' => 'Customer', 'value' => $consignment?->delivery?->customer?->name],
                    ['label' => 'Owed', 'value' => $this->money($approvable->amount_due)],
                    ['label' => 'Collected', 'value' => $this->money($approvable->collected_amount)],
                    ['label' => 'Discount', 'value' => $this->money($approvable->discount_amount)],
                ],
                reason: $approvable->reason,
                viewUrl: null,
                approveUrl: route('sales.courier-door-discounts.decide', [$approvable, 'approve']),
                rejectUrl: route('sales.courier-door-discounts.decide', [$approvable, 'reject']),
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
                viewUrl: route('inventory.stock.index', array_filter([
                    'search' => $approvable->product?->sku,
                    'warehouse_id' => $approvable->warehouse_id,
                ])),
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

        // ⭐ Edit, delete and correct, Phase 0 (17 Oct 2026): a correction above
        // the Tk 20,000 limit, or in a closed month. Approving RE-CHECKS the
        // record and posts the correction; a changed record posts nothing.
        if ($approvable instanceof CorrectionRequest) {
            $route = $approvable->approval_route;

            return $this->card(
                $approvalRequest,
                type: $approvable->action === DocumentAction::Correct ? 'Correction' : 'Reversal',
                summary: $approvable->summary().', '.$approvable->reasonDisplay().', asked by '.($approvable->requestedBy?->name ?? 'somebody'),
                details: [
                    ['label' => 'Record', 'value' => $approvable->document_number],
                    ['label' => 'Amount', 'value' => $approvable->successor_minor === null
                        ? Tk::of((int) $approvable->original_minor)
                        : Tk::of((int) $approvable->original_minor).' to '.Tk::of((int) $approvable->successor_minor)],
                    ['label' => 'Judged on', 'value' => Tk::of((int) $approvable->amount_basis_minor).' (the larger of the old and new amounts)'],
                    ['label' => 'Dates', 'value' => 'Reversal on '.($approvable->dates['reversal'] ?? '').(($approvable->dates['successor'] ?? null) ? ', new record on '.$approvable->dates['successor'] : '')],
                    ['label' => 'Why it waits', 'value' => match (true) {
                        $route?->needsOwnerApproval() && $route?->needsSecondPerson() => 'A closed month, and more than the approval limit',
                        $route?->needsOwnerApproval() => 'A closed month: only the Owner can approve',
                        $approvable->owner_authorised_by !== null => 'More than the approval limit, in a closed month the Owner authorised',
                        default => 'More than the approval limit: a second person approves',
                    }],
                    ['label' => 'Asked by', 'value' => $approvable->requestedBy?->name],
                ],
                reason: $approvable->reasonDisplay(),
                viewUrl: app(DocumentPolicyRegistry::class)->has($approvable->type)
                    ? (fn () => ($doc = $approvable->document) ? app(DocumentPolicyRegistry::class)->for($approvable->type)->urlOf($doc) : null)()
                    : null,
                approveUrl: route('corrections.requests.approve', $approvable),
                rejectUrl: route('corrections.requests.reject', $approvable),
            );
        }

        // ⚠⚠ THE SILENT ROW. Anything reaching here is listed with no approve or
        // reject link at all: visible, unactionable, and it looks like the
        // approver simply has nothing to do. EveryApprovalReachesSomebodyTest
        // exists so a new Approvable model cannot arrive here unnoticed.
        return $this->card(
            $approvalRequest,
            // ⚠ The record can be GONE (a deleted draft, a discarded claim) while
            // its settled request stays in the history: 500 on /approvals/history
            // 29 Sep 2026 read ->id on null here.
            type: class_basename($approvable ?? $approvalRequest->approvable_type),
            summary: $approvable === null
                ? "#{$approvalRequest->approvable_id} (record no longer exists)"
                : "#{$approvable->id}",
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
        // ⚠⚠ NULL FOR ALMOST EVERYTHING, AND THAT IS THE DESIGN. Sending a
        // document back is only honest where the requester has a screen to fix
        // it on and a way to resubmit; offering it on a document with no route
        // home would strand the request in a status nobody can clear. Expense
        // claims have that path, so they set it; nothing else does yet.
        ?string $sendBackUrl = null,
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
            'send_back_url' => $sendBackUrl,
            'requested_by' => $approvalRequest->requestedBy?->name,
            'requested_at' => $approvalRequest->created_at->diffForHumans(),
            'stage' => $approvalRequest->totalStages() > 1 ? $approvalRequest->currentStage()?->name : null,
            // Rule #22 (14 Oct 2026): pending and the viewer would be approving
            // their own money, so the card asks for a reason up front...
            'self_approval' => $approvalRequest->isPending()
                && auth()->user() !== null
                && $approvalRequest->isSelfApprovalBy(auth()->user()),
            // ...and once decided, the visible mark in the history.
            'self_approved' => $approvalRequest->decisions->contains(fn ($decision) => (bool) $decision->self_approved),
        ];
    }

    /**
     * `19 Sep 2026`, or `19 Sep 2026 → 20 Sep 2026`. A date-cast column prints
     * as `2026-09-19 00:00:00` when concatenated, which is what the leave card
     * showed the approver.
     */
    private function dateRange(mixed $start, mixed $end): string
    {
        $from = Carbon::parse($start)->format('j M Y');
        $to = Carbon::parse($end)->format('j M Y');

        return $from === $to ? $from : "{$from} → {$to}";
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

    /**
     * The year a dated record belongs to, for a screen whose filters are
     * year-scoped. Null dates simply drop out of the query string, leaving the
     * screen on its own default.
     */
    private function yearOf(mixed $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        return Carbon::parse((string) $date)->year;
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
