<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Reporting\Definitions\SalarySheetReport;
use App\Modules\Hrm\Reporting\ReportParameters;

/**
 * Two live primary assignments joined the payslip twice: the person printed
 * twice and the money totals counted him twice. One payslip is one row.
 */
it('prints an employee once even when two primary assignments are live', function () {
    $business = Business::create(['name' => 'Once Co', 'slug' => 'once-co']);
    app(BusinessContext::class)->set($business->id);

    $period = PayrollPeriod::create([
        'business_id' => $business->id, 'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30,
    ]);
    $run = PayrollRun::create([
        'business_id' => $business->id, 'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'disbursed', 'employee_count' => 1,
    ]);
    $employee = Employee::create([
        'employee_code' => 'ONCE-1', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active', 'date_of_birth' => '1995-01-01',
    ]);
    foreach (['2025-01-01', '2026-01-01'] as $from) {
        EmployeeAssignment::create(['employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => $from]);
    }
    Payslip::create([
        'business_id' => $business->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
        'gross_salary' => 20000, 'total_earnings' => 20000, 'total_deductions' => 0, 'net' => 20000, 'is_blocked' => false,
    ]);

    $rows = app(SalarySheetReport::class)->rows(new ReportParameters(['run_id' => $run->id, 'include_blocked' => true]))->all();

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['net'])->toBe(20000.0);
});
