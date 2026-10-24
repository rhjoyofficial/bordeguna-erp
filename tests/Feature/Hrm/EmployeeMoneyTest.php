<?php

use App\Models\User;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Support\EmployeeMoney;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * B23 lifetime views (25 Oct 2026; R77): the employee page's money tab.
 * Rana Mia: June and July salary (30,000 each, June paid), a 5,000 advance
 * with 1,000 repaid, one expense claim of 300. A draft payroll is not salary.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Money Co', 'slug' => 'money-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['hrm.employees.manage', 'hrm.payroll.manage', 'accounting.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.employees.manage', 'hrm.payroll.manage']);
    Role::firstOrCreate(['name' => 'HR clerk', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.employees.manage']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
    $this->clerk = User::factory()->create();
    $this->clerk->businesses()->attach($this->business->id);
    $this->clerk->assignRole('HR clerk');
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    $office = Office::create(['business_id' => $this->business->id, 'name' => 'Head office', 'is_active' => true]);
    $this->employee = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000001',
        'first_name' => 'Rana', 'last_name' => 'Mia', 'employment_type' => 'permanent', 'status' => 'active', 'joining_date' => '2025-01-01',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $this->employee->id,
        'office_id' => $office->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $this->slip = function (int $month, string $status, int $net = 30000) {
        $period = PayrollPeriod::firstOrCreate(
            ['business_id' => $this->business->id, 'year' => 2026, 'month' => $month],
            ['start_date' => sprintf('2026-%02d-24', $month - 1), 'end_date' => sprintf('2026-%02d-23', $month), 'divisor_days' => 30],
        );
        $run = PayrollRun::create([
            'business_id' => $this->business->id, 'payroll_period_id' => $period->id, 'version' => 1, 'status' => $status,
            'employee_count' => 1, 'total_earnings' => $net, 'total_deductions' => 0, 'total_net' => $net,
        ]);

        return Payslip::create([
            'business_id' => $this->business->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'gross_salary' => $net, 'total_earnings' => $net, 'total_deductions' => 0, 'net' => $net, 'is_blocked' => false,
        ]);
    };

    $june = ($this->slip)(6, 'locked');
    ($this->slip)(7, 'approved');
    ($this->slip)(8, 'draft');
    PayslipPayment::create([
        'business_id' => $this->business->id, 'payslip_id' => $june->id, 'employee_id' => $this->employee->id,
        'event_type' => 'paid', 'method' => 'bank_transfer', 'amount' => 30000, 'paid_on' => '2026-06-25',
    ]);

    $loan = Loan::create([
        'business_id' => $this->business->id, 'employee_id' => $this->employee->id,
        'reference' => 'ADV-0001', 'type' => 'advance', 'status' => 'active', 'disbursed_on' => '2026-06-01',
        'principal' => 5000, 'installment_amount' => 1000, 'installment_count' => 5,
        'start_year' => 2026, 'start_month' => 7, 'reason' => 'Family', 'applied_at' => now(),
    ]);
    LoanLedgerEntry::create(['business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $this->employee->id, 'entry_type' => LoanLedgerEntryType::Disbursement, 'amount' => 5000, 'occurred_on' => '2026-06-01']);
    LoanLedgerEntry::create(['business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $this->employee->id, 'entry_type' => LoanLedgerEntryType::Repayment, 'amount' => -1000, 'occurred_on' => '2026-07-23']);

    ExpenseClaim::create([
        'business_id' => $this->business->id, 'employee_id' => $this->employee->id,
        'claim_number' => 'EC-0001', 'spent_on' => '2026-07-05', 'amount' => '300', 'purpose' => 'Bus fare', 'created_by' => $this->owner->id,
    ]);
});

it('shows salary paid, advances, claims and the cash account seam on the employee page', function () {
    ($this->as)($this->owner)->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/employees/show')
            ->where('money.range.all_time', true)
            // June and July count; the draft August payroll does not.
            ->has('money.salary.rows', 2)
            ->where('money.salary.net', '60,000.00')
            ->where('money.salary.paid', '30,000.00')
            ->where('money.loans.0.reference', 'ADV-0001')
            ->where('money.loans.0.outstanding', '4,000.00')
            ->where('money.loans.0.repaid', '1,000.00')
            ->where('money.claims.0.number', 'EC-0001')
            ->where('money.claims.0.amount', '300.00')
            ->where('money.cash_account', null)
            // No accounting permission: no ledger heads.
            ->where('money.ledger', null));
});

it('fills the cash account part from the employee cash account he holds', function () {
    $petty = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Petty Cash - Test',
        'type' => MoneyAccountType::EmployeeCash, 'custodian_employee_id' => $this->employee->id,
    ]);

    ($this->as)($this->owner)->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('money.cash_account.accounts.0.id', $petty->id)
            ->where('money.cash_account.accounts.0.name', 'Petty Cash - Test'));
});

it('keeps salary and loans from a reader without the payroll permission', function () {
    ($this->as)($this->clerk)->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('money.salary', null)
            ->where('money.loans', null)
            ->has('money.claims', 1));
});

it('narrows the money tab to a date window', function () {
    ($this->as)($this->owner)->get(route('hrm.employees.show', ['employee' => $this->employee, 'from' => '2026-07-01', 'to' => '2026-07-31']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('money.range.from', '2026-07-01')
            ->has('money.salary.rows', 1)
            ->where('money.salary.net', '30,000.00')
            ->has('money.claims', 1));
});

it('reads the money tab in a fixed number of queries however many payslips there are', function () {
    $count = function () {
        $money = new EmployeeMoney($this->employee->fresh(), $this->owner->fresh(), LifetimeRange::allTime());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $money->props();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // The first read also loads the permission cache; count from the second.
    $count();
    $first = $count();

    foreach ([9, 10, 11] as $month) {
        ($this->slip)($month, 'locked');
    }

    expect($count())->toBe($first);
});

it('exports the money tab, behind the employee permission', function () {
    $csv = ($this->as)($this->owner)->get(route('hrm.employees.money-export', $this->employee))->assertOk()->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Salary')
        ->and($csv)->toContain('EC-0001');

    $stranger = User::factory()->create();
    $stranger->businesses()->attach($this->business->id);
    ($this->as)($stranger)->get(route('hrm.employees.money-export', $this->employee))->assertForbidden();
});
