<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Foundation\Models\Office;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The queued follow-up from the August wipe: an active loan whose scheduled
 * installment produces NO deduction line must never fail silently again.
 * The wipe bug set installments to a status outside the enum; Calculate
 * recovered nothing and said nothing, and the run validated clean.
 *
 * The validator now warns when a recoverable, applied loan with money still
 * outstanding has no scheduled installment for the month - whatever the
 * cause: exhausted schedule, cancelled rows, or corrupt status values. The
 * corrupt case is checked RAW, before the enum cast could throw.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Warn Co', 'slug' => 'warn-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.payroll.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new PayrollWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    HolidayCalendar::create(['name' => 'BD', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $this->shift->id,
        'holiday_calendar_id' => HolidayCalendar::first()->id, 'effective_from' => '2025-01-01',
    ]);
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
    ]);

    $this->period = PayrollPeriod::forMonth(2026, 9);

    // An active, applied loan with 4,000 still owed.
    $this->loan = Loan::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'reference' => 'LN-0001', 'type' => 'advance',
        'status' => 'active',
        'principal' => 6000, 'installment_amount' => 2000, 'installment_count' => 3,
        'start_year' => 2026, 'start_month' => 8,
        'reason' => 'Family need',
        'requested_by' => $this->hr->id,
    ]);
    $this->loan->forceFill(['applied_at' => now()])->save();
    DB::table('loan_ledger_entries')->insert([
        'business_id' => $this->business->id, 'loan_id' => $this->loan->id,
        'employee_id' => $this->employee->id, 'entry_type' => 'disbursement',
        'amount' => 4000, 'occurred_on' => '2026-08-01', 'source_key' => 'test:1',
        'created_by' => $this->hr->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('warns when an active loan will deduct nothing this month', function () {
    // No installment rows at all for September: the schedule has a hole.
    $report = app(ValidatePayrollRun::class)->handle($this->period);

    $loanWarnings = collect($report['warnings'])
        ->flatMap(fn ($row) => $row['warnings'])
        ->filter(fn ($warning) => str_contains($warning, 'LN-0001'));

    expect($loanWarnings)->toHaveCount(1)
        ->and($loanWarnings->first())->toContain('no scheduled installment');
});

it('stays quiet when the installment is properly scheduled', function () {
    DB::table('loan_installments')->insert([
        'loan_id' => $this->loan->id,
        'sequence' => 2, 'year' => 2026, 'month' => 9, 'amount' => 2000,
        'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $report = app(ValidatePayrollRun::class)->handle($this->period);

    $loanWarnings = collect($report['warnings'])
        ->flatMap(fn ($row) => $row['warnings'])
        ->filter(fn ($warning) => str_contains($warning, 'LN-0001'));

    expect($loanWarnings)->toHaveCount(0);
});

it('calls out a corrupt installment status by name - the August wipe bug, remembered', function () {
    // 'pending' is NOT a LoanInstallmentStatus. Exactly the wipe bug: the
    // row exists, Calculate sees nothing, and before this warning the run
    // validated clean.
    DB::table('loan_installments')->insert([
        'loan_id' => $this->loan->id,
        'sequence' => 2, 'year' => 2026, 'month' => 9, 'amount' => 2000,
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $report = app(ValidatePayrollRun::class)->handle($this->period);

    $loanWarnings = collect($report['warnings'])
        ->flatMap(fn ($row) => $row['warnings'])
        ->filter(fn ($warning) => str_contains($warning, 'LN-0001'));

    expect($loanWarnings->count())->toBeGreaterThanOrEqual(1)
        ->and($loanWarnings->join(' '))->toContain('pending');
});
