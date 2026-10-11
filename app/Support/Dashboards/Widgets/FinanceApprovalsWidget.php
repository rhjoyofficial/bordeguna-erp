<?php

namespace App\Support\Dashboards\Widgets;

use App\Models\User;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Collection;

/**
 * The finance part of the person's approval queue: vouchers, payments,
 * transfers, refunds, claims and payroll. Same inbox, filtered by document type.
 */
class FinanceApprovalsWidget extends ApprovalsWaitingWidget
{
    /** The approvable classes (by short name) that are money decisions. */
    public const FINANCE_TYPES = [
        'Voucher', 'IncomeVoucher', 'MoneyTransfer', 'PartyAdjustment', 'SupplierPayment', 'SupplierBill',
        'CustomerRefund', 'ExpenseClaim', 'ExpenseVoucher', 'PayrollRun', 'PayrollUnlockRequest', 'DisbursementBatch',
    ];

    public function key(): string
    {
        return 'finance_approvals';
    }

    public function title(): string
    {
        return 'Money approvals for you';
    }

    public function description(): ?string
    {
        return 'Vouchers, payments, transfers, refunds, claims and payroll you can decide now.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Finance];
    }

    public function authorize(User $user): bool
    {
        return $user->can('accounting.view');
    }

    protected function pending(WidgetContext $context): Collection
    {
        return parent::pending($context)
            ->filter(fn (ApprovalRequest $request) => in_array(class_basename((string) $request->approvable_type), self::FINANCE_TYPES, true)
                || in_array(class_basename($request->approvable !== null ? $request->approvable::class : ''), self::FINANCE_TYPES, true))
            ->values();
    }
}
