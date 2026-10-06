<?php

use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;

/**
 * Finance policy B8 (29 Sep 2026): "Staff purchase recovery capped at 10% of
 * net pay a month, rest rolls forward." Gross 50,000 with no other deduction,
 * so the cap is 5,000 a month.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cap Co', 'slug' => 'cap-co']);
    app(BusinessContext::class)->set($this->business->id);

    (new SalaryComponentSeeder)->run();

    $office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id, 'office_id' => $office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id, 'effective_from' => '2025-01-01',
    ]);

    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 20000],
        ['salary_component_id' => SalaryComponent::where('code', 'medical')->sole()->id, 'value' => 5000],
    ], 'structure');

    $this->july = PayrollPeriod::forMonth(2026, 7);
    $this->august = PayrollPeriod::forMonth(2026, 8);
});

function capStaffPurchase(PayrollPeriod $period, float $amount, int $requestId = 1): void
{
    // Exactly the shape StaffPurchaseController::fulfill writes.
    PayrollAdjustment::create([
        'employee_id' => test()->employee->id,
        'type' => PayrollAdjustmentType::Deduction,
        'amount' => $amount,
        'payroll_period_id' => $period->id,
        'reason' => "Staff purchase SPB-{$requestId}",
        'source_key' => "staff_purchase:{$requestId}",
    ]);
}

function capPayslip(PayrollPeriod $period): Payslip
{
    $run = app(CalculatePayroll::class)->handle($period);

    return $run->payslips()->with('lines')->where('employee_id', test()->employee->id)->sole();
}

function capRecovery(Payslip $payslip): float
{
    return (float) $payslip->lines->where('code', 'staff_purchase')->sum('amount');
}

it('recovers a staff purchase below the cap in full', function () {
    capStaffPurchase($this->july, 2400);

    $payslip = capPayslip($this->july);

    expect(capRecovery($payslip))->toBe(2400.0)
        // Never also as a plain deduction line.
        ->and($payslip->lines->where('code', 'deduction')->count())->toBe(0)
        ->and((float) $payslip->net)->toBe(50000.0 - 2400.0);
});

it('takes no more than 10% of net pay and rolls the rest into the next month', function () {
    capStaffPurchase($this->july, 7000);

    $july = capPayslip($this->july);

    expect(capRecovery($july))->toBe(5000.0)
        ->and($july->lines->firstWhere('code', 'staff_purchase')->formula)->toContain('2,000.00 rolls forward');

    $august = capPayslip($this->august);

    expect(capRecovery($august))->toBe(2000.0);
});

it('recovers the same amount however often an open payroll is re-run', function () {
    capStaffPurchase($this->july, 7000);

    capPayslip($this->july);
    $again = capPayslip($this->july);

    expect(capRecovery($again))->toBe(5000.0);
});

it('ignores a cancelled earlier run when working out what is still owed', function () {
    capStaffPurchase($this->july, 7000);
    capPayslip($this->july)->run->update(['status' => PayrollStatus::Cancelled]);

    // July recovered nothing for real, so August may take up to its own cap.
    expect(capRecovery(capPayslip($this->august)))->toBe(5000.0);
});

it('reads the cap from the Finance policy', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'staff_purchase_cap_percent' => 20]);
    capStaffPurchase($this->july, 7000);

    expect(capRecovery(capPayslip($this->july)))->toBe(7000.0);
});

it('takes nothing when the cap is zero, and carries all of it', function () {
    FinancePolicy::create(['business_id' => $this->business->id, 'staff_purchase_cap_percent' => 0]);
    capStaffPurchase($this->july, 700);

    $payslip = capPayslip($this->july);

    expect(capRecovery($payslip))->toBe(0.0)
        ->and((float) $payslip->net)->toBe(50000.0);
});
