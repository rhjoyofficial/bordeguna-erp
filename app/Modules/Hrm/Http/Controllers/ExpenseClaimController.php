<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\SettleExpenseClaim;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\PayrollPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money an employee spent on the company, and getting it back to them.
 *
 * ⚠⚠ TWO AUDIENCES, ONE MODEL. `mine` is self-scope, exactly like the clock and
 * My Leave: an employee sees and raises only their own. `index` is the finance
 * view of everybody's, and it is the only place a claim can be paid. Mixing them
 * into one screen would mean every employee's list quietly carried a Pay button
 * that 403s.
 */
class ExpenseClaimController extends Controller
{
    /**
     * The employee's own claims.
     */
    public function mine(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $claims = ExpenseClaim::query()
            ->with(['approvalRequest', 'attachments', 'settledBy:id,name'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpenseClaim $claim) => $this->row($claim));

        return Inertia::render('hrm/expense-claims/mine', [
            'claims' => $claims,
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request, StoreAttachment $store, NotifyStageApprovers $notify): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return back()->with('error', 'Your user account is not linked to an employee record yet.');
        }

        $data = $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'purpose' => ['required', 'string', 'max:1000'],
            // ⚠⚠ THE RECEIPT IS REQUIRED AT SUBMISSION, not optional. Joy's
            // requirement was that the office keeps the proof, and proof asked
            // for later is proof that often never arrives.
            'receipt' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'receipt.required' => 'Attach a photo or scan of the receipt. A claim cannot be approved without it.',
            'spent_on.before_or_equal' => 'You cannot claim for a date in the future.',
        ]);

        $businessId = app(BusinessContext::class)->id();

        $claim = ExpenseClaim::create([
            'business_id' => $businessId,
            'employee_id' => $employee->id,
            'claim_number' => ExpenseClaim::nextNumber($businessId),
            'spent_on' => $data['spent_on'],
            'amount' => $data['amount'],
            'purpose' => $data['purpose'],
            'created_by' => $request->user()->id,
        ]);

        $store->handle($claim, $request->file('receipt'), ExpenseClaim::EVIDENCE_COLLECTION, $request->user());

        $approval = $claim->submitForApproval($request->user());
        $notify->handle($approval, "Expense claim {$claim->claim_number} from {$employee->fullName()}");

        return back()->with('success', "Claim {$claim->claim_number} sent for approval.");
    }

    /**
     * Everybody's claims, for the people who approve and pay them.
     */
    public function index(Request $request): Response
    {
        $this->authorize('hrm.payroll.manage');

        $claims = ExpenseClaim::query()
            ->with(['employee:id,employee_code,first_name,last_name', 'approvalRequest', 'attachments', 'expenseAccount:id,code,name', 'settledBy:id,name'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExpenseClaim $claim) => $this->row($claim, withEmployee: true));

        return Inertia::render('hrm/expense-claims/index', [
            'claims' => $claims,
            'moneyAccounts' => MoneyAccount::query()
                ->where('is_active', true)
                ->whereNotNull('account_id')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->all(),
            // ⚠ Newest first: a reimbursement almost always belongs to the month
            // being worked on, not one from last year.
            'payrollPeriods' => PayrollPeriod::query()
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->limit(12)
                ->get()
                ->map(fn (PayrollPeriod $period) => ['id' => $period->id, 'label' => $period->label()])
                ->all(),
            'expenseAccounts' => Account::query()
                ->where('type', AccountType::Expense)
                ->where('is_postable', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])
                ->all(),
        ]);
    }

    /**
     * Say yes to a claim.
     *
     * ⚠⚠ APPROVING IS NOT PAYING, and the two are deliberately separate calls.
     * Somebody with the payroll permission agrees the money is owed; paying it
     * is a second, explicit act that names the account or the salary month. A
     * screen that did both on one press would move money the moment somebody
     * agreed a receipt looked genuine.
     *
     * ⚠ The stage decides who may act, not this method - `canBeActionedBy`
     * reads the workflow, so changing who approves is a seeder row and not a
     * code change.
     */
    public function approve(Request $request, ExpenseClaim $claim, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $claim->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        if (! $approval->approve($request->user(), $this->note($request))) {
            $notify->handle($approval->fresh(), "Expense claim {$claim->claim_number}");

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        return back()->with('success', "Claim {$claim->claim_number} approved. It can now be paid.");
    }

    public function reject(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $approval = $claim->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $approval->reject($request->user(), $this->note($request));

        // ⚠ The claim itself is left exactly as it was. A rejected claim is a
        // record that the employee asked and was turned down, and rewriting or
        // deleting it would lose the only proof the request was ever made.
        return back()->with('success', "Claim {$claim->claim_number} rejected. No money moved.");
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
    }

    /**
     * Pay the claim, by whichever route the office chose.
     */
    public function settle(Request $request, ExpenseClaim $claim, SettleExpenseClaim $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'method' => ['required', Rule::in([ExpenseClaim::SETTLE_PAYMENT, ExpenseClaim::SETTLE_PAYROLL])],
            'expense_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            // ⚠ Required only for the pay-now route. A salary settlement moves
            // no money today and has no account to move it from.
            'money_account_id' => [
                Rule::requiredIf(fn () => $request->input('method') === ExpenseClaim::SETTLE_PAYMENT),
                'nullable',
                'integer',
                Rule::exists('money_accounts', 'id'),
            ],
            // ⚠ Required only for the salary route, and named rather than
            // inferred: an adjustment belongs to one payroll month.
            'payroll_period_id' => [
                Rule::requiredIf(fn () => $request->input('method') === ExpenseClaim::SETTLE_PAYROLL),
                'nullable',
                'integer',
                Rule::exists('payroll_periods', 'id'),
            ],
        ]);

        $expenseAccount = Account::findOrFail($data['expense_account_id']);

        if ($data['method'] === ExpenseClaim::SETTLE_PAYROLL) {
            $period = PayrollPeriod::findOrFail($data['payroll_period_id']);
            $action->withSalary($claim, $expenseAccount, $period, $request->user());

            return back()->with('success', "Claim {$claim->claim_number} will be paid with the {$period->label()} salary.");
        }

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);
        $settled = $action->payNow($claim, $moneyAccount, $expenseAccount, $request->user());

        return back()->with(
            'success',
            "Claim {$claim->claim_number} paid from {$moneyAccount->name}. Payment voucher "
            .($settled->voucher?->voucher_number ?? '').' created with the receipt attached.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ExpenseClaim $claim, bool $withEmployee = false): array
    {
        $row = [
            'id' => $claim->id,
            'claim_number' => $claim->claim_number,
            // ⚠ toDateString(), not the cast: a `date` cast serialises in UTC
            // and Dhaka is UTC+6, so midnight local renders a day early.
            'spent_on' => $claim->spent_on?->toDateString(),
            'amount' => (string) (float) $claim->amount,
            'purpose' => $claim->purpose,
            'state' => $claim->state(),
            'is_settled' => $claim->isSettled(),
            'is_approved' => $claim->isApproved(),
            'settlement_method' => $claim->settlement_method,
            'settled_by' => $claim->settledBy?->name,
            'expense_account' => $claim->expenseAccount?->label(),
            'receipts' => $claim->attachments->map(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->file_name,
                'url' => route('attachments.show', $file->id),
            ])->values()->all(),
        ];

        if ($withEmployee) {
            $row['employee'] = $claim->employee?->fullName();
            $row['employee_code'] = $claim->employee?->employee_code;
        }

        return $row;
    }
}
