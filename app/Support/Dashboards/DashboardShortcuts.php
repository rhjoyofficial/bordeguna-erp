<?php

namespace App\Support\Dashboards;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Support\ApprovalInbox;

/**
 * The Shortcuts row of each role dashboard (redesign round 3, 16 Oct 2026):
 * links only, built HERE so every gate is the server's own check. A shortcut
 * the person may not open is not sent, the same rule as the sidebar.
 *
 * ⚠ A shortcut needs EVERY permission listed: the action's (create a purchase
 * order) and the read gate of the page it lands on (the purchase orders list,
 * purchasing.reports.view). Purchase orders, expenses, customers and sales
 * orders have no create address; they are pop-ups on their list pages
 * (round3-VERIFY W-E6), so the shortcut opens the list.
 */
final class DashboardShortcuts
{
    /** Pseudo-permissions answered here rather than by a Gate. */
    private const APPROVALS = '@approvals';

    private const EMPLOYEE = '@employee';

    /** @var array<string, array{label: string, url: string, needs: list<string>}> */
    private const ALL = [
        'sale' => ['label' => '+ Sale at the counter', 'url' => '/sales/counter', 'needs' => ['sales.sell']],
        'sales_order' => ['label' => '+ Sales order', 'url' => '/sales/orders', 'needs' => ['sales.orders.record', 'sales.orders.see']],
        'purchase_order' => ['label' => '+ Purchase order', 'url' => '/purchasing/purchase-orders', 'needs' => ['purchasing.po.create', 'purchasing.reports.view']],
        'expense' => ['label' => '+ Expense', 'url' => '/purchasing/expenses', 'needs' => ['purchasing.expense.record', 'purchasing.reports.view']],
        'payment_voucher' => ['label' => '+ Payment voucher', 'url' => '/accounting/vouchers/payment/create', 'needs' => ['accounting.voucher.prepare']],
        'receipt_voucher' => ['label' => '+ Receipt voucher', 'url' => '/accounting/vouchers/receipt/create', 'needs' => ['accounting.voucher.prepare']],
        // The vouchers.create route checks voucher.prepare only (W-E6 b), not journal.post.
        'journal_voucher' => ['label' => '+ Journal voucher', 'url' => '/accounting/vouchers/journal/create', 'needs' => ['accounting.voucher.prepare']],
        'bank_statement' => ['label' => 'Import bank statement', 'url' => '/accounting/bank-statements', 'needs' => ['accounting.view']],
        'pay_claims' => ['label' => 'Pay expense claims', 'url' => '/hrm/expense-claims', 'needs' => ['hrm.payroll.manage']],
        // No receive screen of its own: goods are received from the purchase order (W-E6 d).
        'receive_goods' => ['label' => 'Receive goods', 'url' => '/purchasing/purchase-orders', 'needs' => ['purchasing.receipt.record', 'purchasing.reports.view']],
        'stock' => ['label' => 'Stock', 'url' => '/inventory/stock', 'needs' => ['inventory.reports.view']],
        'daily_attendance' => ['label' => 'Daily attendance', 'url' => '/hrm/attendance/daily', 'needs' => ['hrm.attendance.manage']],
        'approvals' => ['label' => 'Approvals', 'url' => '/approvals', 'needs' => [self::APPROVALS]],
        'customer' => ['label' => '+ Customer', 'url' => '/sales/customers', 'needs' => ['sales.customers.manage', 'sales.reports.view']],
        'followups' => ['label' => 'Follow-ups', 'url' => '/crm/queue', 'needs' => ['crm.leads.view']],
        'clock' => ['label' => 'Clock in or out', 'url' => '/hrm/clock', 'needs' => [self::EMPLOYEE]],
        'leave' => ['label' => 'Apply for leave', 'url' => '/hrm/my-leave', 'needs' => [self::EMPLOYEE]],
        'expense_claim' => ['label' => '+ Expense claim', 'url' => '/hrm/my-expenses', 'needs' => [self::EMPLOYEE]],
        'staff_purchase' => ['label' => 'Staff purchase', 'url' => '/hrm/staff-purchase', 'needs' => [self::EMPLOYEE]],
        'payslips' => ['label' => 'My payslips', 'url' => '/hrm/my-payslips', 'needs' => [self::EMPLOYEE]],
    ];

    /** Which shortcuts each dashboard offers, in order (workspace-design MAPPING §4). */
    private const BY_DASHBOARD = [
        'owner' => ['sale', 'sales_order', 'purchase_order', 'expense', 'payment_voucher', 'receipt_voucher', 'journal_voucher'],
        'finance' => ['payment_voucher', 'receipt_voucher', 'journal_voucher', 'bank_statement', 'pay_claims'],
        'manager' => ['sales_order', 'receive_goods', 'stock', 'daily_attendance', 'approvals'],
        'sales' => ['sale', 'sales_order', 'customer', 'followups'],
        'employee' => ['clock', 'leave', 'expense_claim', 'staff_purchase', 'payslips'],
    ];

    public function __construct(
        private readonly ApprovalInbox $inbox,
        private readonly BusinessContext $context,
    ) {}

    /** @return list<array{key: string, label: string, url: string}> */
    public function for(DashboardKey $dashboard, User $user): array
    {
        $answers = [];

        $allowed = function (string $need) use ($user, &$answers): bool {
            return $answers[$need] ??= match ($need) {
                self::APPROVALS => $this->canReviewApprovals($user),
                self::EMPLOYEE => $this->hasEmployeeProfile($user),
                default => $user->can($need),
            };
        };

        $shortcuts = [];

        foreach (self::BY_DASHBOARD[$dashboard->value] as $key) {
            $shortcut = self::ALL[$key];

            if (collect($shortcut['needs'])->every($allowed)) {
                $shortcuts[] = ['key' => $key, 'label' => $shortcut['label'], 'url' => $shortcut['url']];
            }
        }

        return $shortcuts;
    }

    /** The sidebar's canReviewApprovals (HandleInertiaRequests), the gate of the Approvals screen. */
    private function canReviewApprovals(User $user): bool
    {
        return $user->can('inventory.stock.approve')
            || $user->can('purchasing.po.approve')
            || $this->inbox->countFor($user) > 0;
    }

    /** The sidebar's hasEmployeeProfile: the Me screens are self-scope on an employee record here. */
    private function hasEmployeeProfile(User $user): bool
    {
        return (bool) Employee::where('user_id', $user->id)->first()?->isEmployedIn($this->context->id());
    }
}
