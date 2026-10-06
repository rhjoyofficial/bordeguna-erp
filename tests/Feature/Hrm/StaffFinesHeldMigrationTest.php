<?php

use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The live re-point of `payroll.deduction.penalty` (Finance policy B6): a chart
 * and mapping exactly as production had them on 6 Oct 2026 - 2147 missing,
 * penalty on 6131 since 2000-01-01.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-06 10:00:00');

    $this->business = Business::create(['name' => 'Fine Co', 'slug' => 'fine-co']);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);

    DB::table('accounts')->where('code', '2147')->delete();

    AccountMapping::create([
        'key' => 'payroll.deduction.penalty',
        'account_id' => Account::bySlug('salaries_expense')->id,
        'effective_from' => '2000-01-01',
    ]);

    $this->migration = require base_path('app/Modules/Hrm/database/migrations/2026_10_06_110000_staff_fines_are_held_not_wage_credit.php');
    $this->penaltyOn = fn (string $date) => AccountMapping::resolve('payroll.deduction.penalty', Carbon::parse($date))->code;
});

it('plants 2147 and re-points the whole history when no fine was ever deducted', function () {
    $this->migration->up();
    $this->migration->up();

    $held = Account::bySlug('staff_fines_held');

    expect($held->code)->toBe('2147')
        ->and($held->parent?->code)->toBe('2100')
        ->and($held->is_postable)->toBeTrue()
        ->and(($this->penaltyOn)('2026-09-15'))->toBe('2147')
        ->and(($this->penaltyOn)('2026-10-06'))->toBe('2147')
        ->and(DB::table('account_mappings')->where('key', 'payroll.deduction.penalty')->count())->toBe(1);
});

it('keeps a month that already deducted a fine on the old account, and holds from today', function () {
    $period = PayrollPeriod::forMonth(2026, 9);
    $run = PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 1, 'status' => PayrollStatus::Locked,
    ]);
    $employee = Employee::create([
        'employee_code' => 'BDG-000001', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $payslip = Payslip::create([
        'business_id' => $this->business->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
        'gross_salary' => '30000.00', 'total_earnings' => '30000.00', 'total_deductions' => '300.00', 'net' => '29700.00',
    ]);
    PayslipLine::create([
        'payslip_id' => $payslip->id, 'code' => 'penalty', 'label' => 'Penalty', 'type' => 'deduction',
        'amount' => '300.00', 'formula' => 'fixed', 'source' => 'test', 'sequence' => 1,
    ]);

    $this->migration->up();
    $this->migration->up();

    expect(($this->penaltyOn)('2026-09-15'))->toBe('6131')
        ->and(($this->penaltyOn)('2026-10-06'))->toBe('2147')
        ->and(DB::table('account_mappings')->where('key', 'payroll.deduction.penalty')->count())->toBe(2);
});
