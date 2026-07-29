<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ImportManualPayroll;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ManualPayrollLine;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Reporting\Definitions\ParallelRunReconciliationReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * W2 — the ROL-04 go-live acceptance artefact (Correction C16).
 *
 * ⚠⚠ The first test is the one this whole slice exists for. ROL-03 permits a
 * difference of up to 1 BDT ONLY where decimal rounding is the actual cause,
 * and NOTHING for a formula, attendance, leave or policy cause. A report that
 * classified by magnitude would pass a wrong overtime rate silently.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Recon Co', 'slug' => 'recon-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.payroll.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.payroll.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->period = PayrollPeriod::forMonth(2026, 7);
    $this->run = PayrollRun::create([
        'payroll_period_id' => $this->period->id,
        'status' => 'draft',
        'version' => 1,
    ]);
});

function reconEmployee(string $code, string $first = 'Karim', string $last = 'Ahmed'): Employee
{
    $employee = Employee::create([
        'employee_code' => $code,
        'first_name' => $first, 'last_name' => $last,
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $employee->id,
        'office_id' => test()->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    return $employee;
}

/**
 * A system payslip with its lines, exactly as CalculatePayroll would leave it.
 *
 * @param  array<string, array{0: string, 1: float}>  $lines
 */
function reconPayslip(Employee $employee, float $net, array $lines): Payslip
{
    $payslip = Payslip::create([
        'payroll_run_id' => test()->run->id,
        'employee_id' => $employee->id,
        'gross_salary' => $net,
        'total_earnings' => $net,
        'total_deductions' => 0,
        'net' => $net,
        'is_blocked' => false,
    ]);

    $sequence = 1;
    foreach ($lines as $code => [$label, $amount]) {
        $payslip->lines()->create([
            'code' => $code,
            'label' => $label,
            'type' => 'earning',
            'amount' => $amount,
            'formula' => number_format($amount, 2),
            'source' => 'salary_structure',
            'sequence' => $sequence++,
        ]);
    }

    return $payslip;
}

function reconRows(array $overrides = []): array
{
    $report = new ParallelRunReconciliationReport;

    $rows = $report->rows(new ReportParameters([
        'run_id' => test()->run->id,
        'differences_only' => false,
        ...$overrides,
    ]))->all();

    return array_values($rows);
}

function reconRow(array $rows, string $code, string $item): ?array
{
    foreach ($rows as $row) {
        if ($row['employee_code'] === $code && $row['item'] === $item) {
            return $row;
        }
    }

    return null;
}

// =============================================================================
// ⚠⚠ CORRECTION C16b — the report classifies, it never concludes.
// =============================================================================

it('does not report a sub-1-BDT difference as passing', function () {
    $employee = reconEmployee('BDG-000001');
    reconPayslip($employee, 12000.40, ['basic' => ['Basic', 12000.40]]);

    ManualPayrollLine::create([
        'payroll_period_id' => $this->period->id,
        'employee_id' => $employee->id,
        'source_employee_ref' => 'BDG-000001',
        'item_label' => 'Net', 'item_key' => 'net', 'amount' => 12000.00,
    ]);

    $row = reconRow(reconRows(), 'BDG-000001', 'Net pay');

    // 0.40 could be rounding — or a wrong overtime rate, which ROL-03 rejects
    // at ANY size. The system cannot tell, so it must not decide.
    expect($row['difference'])->toBe(0.40);
    expect($row['status'])->toBe('Within rounding range — confirm');
    expect($row['status'])->not->toContain('Pass');
    expect($row['note'])->toContain('ONLY if rounding is the actual cause');
});

it('reports an exact match as a match', function () {
    $employee = reconEmployee('BDG-000001');
    reconPayslip($employee, 12000.00, ['basic' => ['Basic', 12000.00]]);

    ManualPayrollLine::create([
        'payroll_period_id' => $this->period->id,
        'employee_id' => $employee->id,
        'source_employee_ref' => 'BDG-000001',
        'item_label' => 'Net', 'item_key' => 'net', 'amount' => 12000.00,
    ]);

    expect(reconRow(reconRows(), 'BDG-000001', 'Net pay')['status'])->toBe('Match');
});

it('reports a difference above 1 BDT as a discrepancy regardless of cause', function () {
    $employee = reconEmployee('BDG-000001');
    reconPayslip($employee, 12500.00, ['basic' => ['Basic', 12500.00]]);

    ManualPayrollLine::create([
        'payroll_period_id' => $this->period->id,
        'employee_id' => $employee->id,
        'source_employee_ref' => 'BDG-000001',
        'item_label' => 'Net', 'item_key' => 'net', 'amount' => 12000.00,
    ]);

    $row = reconRow(reconRows(), 'BDG-000001', 'Net pay');
    expect($row['status'])->toBe('Discrepancy');
    expect($row['difference'])->toBe(500.00);
});

it('never prints the word Pass anywhere in its output', function () {
    $employee = reconEmployee('BDG-000001');
    reconPayslip($employee, 12000.40, ['basic' => ['Basic', 12000.40]]);

    ManualPayrollLine::create([
        'payroll_period_id' => $this->period->id,
        'employee_id' => $employee->id,
        'source_employee_ref' => 'BDG-000001',
        'item_label' => 'Net', 'item_key' => 'net', 'amount' => 12000.00,
    ]);

    foreach (reconRows() as $row) {
        expect(strtolower($row['status']))->not->toContain('pass');
    }
});

it('states ROL-03 on every rendering, not only in the code', function () {
    $meta = (new ParallelRunReconciliationReport)->meta(new ReportParameters(['run_id' => $this->run->id]));

    // The signatories never read a docblock.
    expect($meta->note)->toContain('ONLY where decimal rounding is the actual cause');
    expect($meta->note)->toContain('does not approve');
});

// =============================================================================
// C16c / C16d — one-sided data is labelled, never folded in as a zero.
// =============================================================================

it('labels an employee missing from the manual sheet instead of showing a total difference', function () {
    $employee = reconEmployee('BDG-000002', 'Shuborna', 'Mostafa');
    reconPayslip($employee, 10000.00, ['basic' => ['Basic', 10000.00]]);

    $row = reconRow(reconRows(), 'BDG-000002', 'Net pay');

    // ⚠ C10c's rule reused: a 100% difference describing a non-event buries
    // the one line that matters.
    expect($row['status'])->toBe('Not on manual sheet');
    expect($row['difference'])->toBeNull();
});

it('reports an employee the manual process paid but the system did not', function () {
    reconEmployee('BDG-000001');
    reconPayslip(Employee::where('employee_code', 'BDG-000001')->sole(), 12000.00, ['basic' => ['Basic', 12000.00]]);

    ManualPayrollLine::create([
        'payroll_period_id' => $this->period->id,
        'employee_id' => null,
        'source_employee_ref' => 'BDG-000099',
        'item_label' => 'Net', 'item_key' => 'net', 'amount' => 9000.00,
    ]);

    // The most serious finding a parallel run can produce: somebody is being
    // paid who the system does not know about. Dropping them would hide it.
    $row = reconRow(reconRows(), 'BDG-000099', 'Net pay');
    expect($row)->not->toBeNull();
    expect($row['status'])->toBe('Not in system payroll');
    expect($row['manual_amount'])->toBe(9000.00);
});

it('lists a manual item the system produced no line for, rather than treating it as zero', function () {
    $employee = reconEmployee('BDG-000001');
    reconPayslip($employee, 12000.00, ['basic' => ['Basic', 12000.00]]);

    foreach ([['Net', 'net', 12000.00], ['Loan', 'loan_repayment', 500.00]] as [$label, $key, $amount]) {
        ManualPayrollLine::create([
            'payroll_period_id' => $this->period->id,
            'employee_id' => $employee->id,
            'source_employee_ref' => 'BDG-000001',
            'item_label' => $label, 'item_key' => $key, 'amount' => $amount,
        ]);
    }

    $row = reconRow(reconRows(), 'BDG-000001', 'Loan');
    expect($row['status'])->toBe('Manual only');
    expect($row['system_amount'])->toBeNull();
});

// =============================================================================
// C16a — the import.
// =============================================================================

it('imports a wide payroll sheet and matches employees by code', function () {
    reconEmployee('BDG-000001');

    $csv = "Employee Code,Basic,OT,Net\nBDG-000001,12000,500,12500\n";
    $result = app(ImportManualPayroll::class)->handle($this->period, $csv, 'july.csv');

    expect($result['imported'])->toBe(3);
    expect($result['failures'])->toBe([]);
    expect(ManualPayrollLine::forPeriod($this->period->id)->count())->toBe(3);
    expect(ManualPayrollLine::where('item_key', 'overtime')->sole()->amount)->toEqual(500.00);
});

it('is re-runnable, updating rather than duplicating', function () {
    reconEmployee('BDG-000001');

    $first = app(ImportManualPayroll::class)->handle($this->period, "Code,Net\nBDG-000001,12000\n");
    $second = app(ImportManualPayroll::class)->handle($this->period, "Code,Net\nBDG-000001,12500\n");

    expect($first['imported'])->toBe(1);
    expect($second['imported'])->toBe(0);
    expect($second['updated'])->toBe(1);
    expect(ManualPayrollLine::forPeriod($this->period->id)->count())->toBe(1);
    expect(ManualPayrollLine::sole()->amount)->toEqual(12500.00);
});

it('imports a row for an employee it cannot match, and says so', function () {
    $result = app(ImportManualPayroll::class)->handle($this->period, "Code,Net\nBDG-999999,5000\n");

    // Not dropped: somebody on the manual payroll who is not in the system is
    // the finding, not an import error.
    expect($result['unmatched_employees'])->toBe(['BDG-999999']);
    expect(ManualPayrollLine::sole()->employee_id)->toBeNull();
});

it('imports an unrecognised column instead of silently dropping it', function () {
    reconEmployee('BDG-000001');

    $result = app(ImportManualPayroll::class)->handle($this->period, "Code,Net,Tiffin Allowance\nBDG-000001,12000,300\n");

    // A silently ignored column is a figure nobody compared.
    expect($result['unknown_items'])->toContain('Tiffin Allowance');
    expect(ManualPayrollLine::where('item_key', 'tiffinallowance')->exists())->toBeTrue();
});

it('reports a non-numeric cell with its line number rather than failing the file', function () {
    reconEmployee('BDG-000001');

    $result = app(ImportManualPayroll::class)->handle($this->period, "Code,Net\nBDG-000001,not a number\n");

    expect($result['failures'][0])->toContain('Line 2');
    expect($result['imported'])->toBe(0);
});

it('refuses a file with no employee column, listing what it found', function () {
    expect(fn () => app(ImportManualPayroll::class)->handle($this->period, "Foo,Bar\n1,2\n"))
        ->toThrow(RuntimeException::class, 'No employee column found');
});

it('reads an accountant parenthesised negative as a negative', function () {
    reconEmployee('BDG-000001');

    app(ImportManualPayroll::class)->handle($this->period, "Code,Loan\nBDG-000001,\"(500)\"\n");

    expect(ManualPayrollLine::sole()->amount)->toEqual(-500.00);
});
