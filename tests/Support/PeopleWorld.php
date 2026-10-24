<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Assets\Actions\ChargeDepreciation;
use App\Modules\Assets\Actions\RecordFixedAsset;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Hrm\Actions\DisburseLoan;
use App\Modules\Hrm\Actions\PostExpenseClaimApproval;
use App\Modules\Hrm\Actions\SettleExpenseClaim;
use App\Modules\Hrm\database\seeders\ExpenseClaimWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\ExpenseClaimLine;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\ExpenseCategories;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): the CorrectionWorld (17 Oct 2026,
 * Owner, three Finance users, Manager, Sales, BRAC Bank and Cash) plus what
 * the people and asset corrections need: payroll and asset ledger mappings,
 * the expense claim workflow, an employee, and the permissions:
 *
 *   Owner     hrm.payroll.manage, assets.view, assets.manage, assets.correct
 *   Finance   hrm.payroll.manage (the payroll manager, NOT the Owner)
 *   Manager   assets.view only
 */
final class PeopleWorld
{
    public Employee $employee;

    public User $staff;

    public static function make(CorrectionWorld $w): self
    {
        $p = new self;
        $p->w = $w;

        foreach (['hrm.payroll.manage', 'assets.view', 'assets.manage', 'assets.correct'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $role = fn (string $name) => Role::where('name', $name)->where('business_id', $w->business->id)->firstOrFail();
        $role('Owner')->givePermissionTo(['hrm.payroll.manage', 'assets.view', 'assets.manage', 'assets.correct']);
        $role('Finance')->givePermissionTo(['hrm.payroll.manage']);
        $role('Manager')->givePermissionTo(['assets.view']);

        (new PayrollAccountMappingSeeder)->run();
        (new AssetAccountMappingSeeder)->run();
        (new ExpenseClaimWorkflowSeeder)->run();
        $w->enter($w->business);
        ExpenseCategories::ensureDefaults($w->business->id);

        $p->staff = $w->member('Sales', 'Rakib');
        $p->employee = Employee::create([
            'business_id' => $w->business->id,
            'user_id' => $p->staff->id,
            'employee_code' => 'BDG-000031',
            'first_name' => 'Rakib', 'last_name' => 'Hossain',
            'joining_date' => '2026-01-01', 'status' => 'active', 'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create([
            'business_id' => $w->business->id, 'employee_id' => $p->employee->id,
            'effective_from' => '2026-01-01', 'is_primary' => true,
        ]);

        return $p;
    }

    public CorrectionWorld $w;

    public function reason(string $text = 'Typed the wrong amount'): ActionReason
    {
        return new ActionReason(null, $text);
    }

    /** A submitted, then approved claim with one category spend: the approval journal is posted. */
    public function approvedClaim(string $amount = '500', ?User $approver = null): ExpenseClaim
    {
        $category = (int) DB::table('expense_categories')->where('business_id', $this->w->business->id)->whereNotNull('account_id')->orderBy('sort')->value('id');

        $claim = ExpenseClaim::create([
            'business_id' => $this->w->business->id,
            'employee_id' => $this->employee->id,
            'claim_number' => ExpenseClaim::nextNumber($this->w->business->id),
            'spent_on' => '2026-10-05',
            'amount' => $amount,
            'created_by' => $this->staff->id,
        ]);
        ExpenseClaimLine::create([
            'business_id' => $this->w->business->id, 'expense_claim_id' => $claim->id, 'spent_on' => '2026-10-05',
            'expense_category_id' => $category, 'amount' => $amount, 'description' => 'Courier to Savar', 'sort' => 1,
        ]);
        $claim->forceFill(['submitted_at' => now()])->save();
        $claim->submitForApproval($this->staff)->approve($approver ?? $this->w->owner);
        app(PostExpenseClaimApproval::class)->handle($claim->fresh()->load('lines'), $approver ?? $this->w->owner);

        return $claim->fresh();
    }

    public function paidNow(ExpenseClaim $claim): ExpenseClaim
    {
        return app(SettleExpenseClaim::class)->payNow($claim->fresh(), $this->w->cash, null, $this->w->owner);
    }

    public function period(int $year, int $month): PayrollPeriod
    {
        return PayrollPeriod::forMonth($year, $month);
    }

    public function onSalary(ExpenseClaim $claim, PayrollPeriod $period): ExpenseClaim
    {
        return app(SettleExpenseClaim::class)->withSalary($claim->fresh(), null, $period, $this->w->owner);
    }

    /** A generated adjustment (no approval of its own), like an arrear. */
    public function generated(PayrollPeriod $period, PayrollAdjustmentType $type = PayrollAdjustmentType::Arrear, string $amount = '1200'): PayrollAdjustment
    {
        return PayrollAdjustment::once('test_arrear:'.uniqid(), [
            'business_id' => $this->w->business->id,
            'employee_id' => $this->employee->id,
            'payroll_period_id' => $period->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => 'Back pay for September',
            'requested_by' => $this->w->owner->id,
        ]);
    }

    /** An approved manual adjustment. */
    public function approvedAdjustment(PayrollPeriod $period, PayrollAdjustmentType $type = PayrollAdjustmentType::Addition, string $amount = '800'): PayrollAdjustment
    {
        $adjustment = PayrollAdjustment::create([
            'business_id' => $this->w->business->id,
            'employee_id' => $this->employee->id,
            'payroll_period_id' => $period->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => 'Night duty',
            'requested_by' => $this->w->financeA->id,
        ]);
        $adjustment->submitForApproval($this->w->financeA)->approve($this->w->owner);

        return $adjustment->fresh();
    }

    public function disbursedLoan(string $principal = '6000', string $on = '2026-10-10'): Loan
    {
        $loan = Loan::create([
            'business_id' => $this->w->business->id,
            'employee_id' => $this->employee->id,
            'reference' => 'LOAN-'.str_pad((string) (Loan::count() + 1), 4, '0', STR_PAD_LEFT),
            'type' => LoanType::Advance,
            'status' => LoanStatus::Pending,
            'principal' => $principal,
            'installment_amount' => '2000',
            'installment_count' => 3,
            'start_year' => 2026,
            'start_month' => 11,
            'reason' => 'Medical',
            'requested_by' => $this->w->financeA->id,
        ]);

        return app(DisburseLoan::class)->handle($loan, $this->w->bank, $this->w->owner, $on);
    }

    /** A fixed asset bought from BRAC Bank (the purchase journal is posted). */
    public function asset(string $cost = '120000', string $on = '2026-07-15', string $code = 'FA-0001', int $life = 12): FixedAsset
    {
        return app(RecordFixedAsset::class)->handle(
            assetCode: $code,
            name: 'Delivery van',
            assetAccount: Account::where('code', '1220')->first() ?? Account::query()->where('type', 'asset')->where('is_postable', true)->whereNull('system_slug')->where('code', 'like', '12%')->firstOrFail(),
            acquiredOn: $on,
            cost: $cost,
            usefulLifeMonths: $life,
            createdBy: $this->w->financeA,
            moneyAccount: $this->w->bank,
        );
    }

    public function depreciate(int $year, int $month): void
    {
        app(ChargeDepreciation::class)->handle($year, $month, $this->w->owner);
    }

    /** Claims a journal line on a bank statement line (as CorrectionWorld::matchOnBankStatement). */
    public function matchLine(int $journalLineId, string $date, string $amount): int
    {
        $import = DB::table('bank_statement_imports')->insertGetId([
            'business_id' => $this->w->business->id, 'money_account_id' => $this->w->bank->id, 'filename' => 'brac.csv',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('bank_statement_lines')->insertGetId([
            'business_id' => $this->w->business->id, 'import_id' => $import, 'money_account_id' => $this->w->bank->id,
            'txn_date' => $date, 'amount' => $amount, 'match_status' => 'matched',
            'matched_journal_line_id' => $journalLineId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The sum of every journal line on a head, in paisa. */
    public function balance(string $slugOrCode): int
    {
        $account = Account::bySlug($slugOrCode) ?? Account::where('code', $slugOrCode)->firstOrFail();

        return (int) JournalLine::query()->where('account_id', $account->id)->sum('amount_minor');
    }
}
