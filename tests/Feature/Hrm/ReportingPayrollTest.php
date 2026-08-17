<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\PaymentEventType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Reporting\Definitions\CostAllocationReport;
use App\Modules\Hrm\Reporting\Definitions\EarningsDeductionsBreakdownReport;
use App\Modules\Hrm\Reporting\Definitions\LoanBalanceReport;
use App\Modules\Hrm\Reporting\Definitions\PayrollLineDetailReport;
use App\Modules\Hrm\Reporting\Definitions\PayrollVarianceReport;
use App\Modules\Hrm\Reporting\Definitions\SettlementWorksheetReport;
use App\Modules\Hrm\Reporting\Definitions\UnpaidSalaryReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Hrm\Reporting\ReportRunner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * W3c — the RPT-02 payroll reports, the settlement worksheet and the
 * re-expressed accounting export.
 *
 * Payslips are built directly here rather than run through the engine. That is
 * the boundary these reports actually sit on: they read finished payslips and
 * recompute nothing, so a test that produced them through the engine would be
 * testing the engine.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Payroll Co', 'slug' => 'payroll-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.payroll.manage', 'hrm.salary.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.payroll.manage', 'hrm.salary.view']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->department = Department::create([
        'name' => 'Production', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    $this->makeEmployee = function (string $code, string $name, array $attributes = []) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $name, 'last_name' => 'Ahmed',
            'employment_type' => 'permanent', 'status' => 'active',
            ...$attributes,
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    };

    $this->makeRun = function (int $year, int $month) {
        $period = PayrollPeriod::create([
            'business_id' => $this->business->id,
            'year' => $year, 'month' => $month,
            // C7b's 24th → 23rd cycle.
            'start_date' => sprintf('%04d-%02d-24', $year, $month - 1),
            'end_date' => sprintf('%04d-%02d-23', $year, $month),
            'divisor_days' => 30,
        ]);

        return PayrollRun::create([
            'business_id' => $this->business->id,
            'payroll_period_id' => $period->id,
            'version' => 1, 'status' => 'disbursed',
            'employee_count' => 0,
        ]);
    };

    $this->makePayslip = function (PayrollRun $run, Employee $employee, array $lines, float $net) {
        $payslip = Payslip::create([
            'business_id' => $this->business->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'gross_salary' => $net, 'total_earnings' => $net,
            'total_deductions' => 0, 'net' => $net,
            'is_blocked' => false,
        ]);

        $sequence = 0;

        foreach ($lines as $code => $line) {
            PayslipLine::create([
                'payslip_id' => $payslip->id,
                'code' => $code,
                'label' => $line['label'],
                'type' => $line['type'] ?? 'earning',
                'amount' => $line['amount'],
                'formula' => $line['formula'] ?? 'Fixed',
                'source' => $line['source'] ?? 'structure',
                'sequence' => $sequence++,
            ]);
        }

        return $payslip;
    };

    $this->runner = app(ReportRunner::class);

    $this->rows = fn ($definition, array $parameters) => iterator_to_array(
        $this->runner->run($definition, new ReportParameters($parameters), $this->hr)->rows,
    );
});

/*
|--------------------------------------------------------------------------
| #10 — Payroll variance (Correction C10c)
|--------------------------------------------------------------------------
*/

it('never shows a joiner as a 100% variance, which is the whole point of C10c', function () {
    $previous = ($this->makeRun)(2026, 6);
    $current = ($this->makeRun)(2026, 7);

    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');
    $joiner = ($this->makeEmployee)('BDG-000002', 'Nadia');

    // Karim was in both runs; his basic went up.
    ($this->makePayslip)($previous, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 25000]], 25000);
    ($this->makePayslip)($current, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 27000]], 27000);

    // Nadia joined this month and has no previous figure at all.
    ($this->makePayslip)($current, $joiner, ['BASIC' => ['label' => 'Basic', 'amount' => 30000]], 30000);

    $rows = ($this->rows)(app(PayrollVarianceReport::class), [
        'run_id' => $current->id, 'compare_run_id' => $previous->id, 'movements_only' => true,
    ]);

    $byEmployee = collect($rows)->groupBy('employee_code');

    // Karim: a real movement, with a real percentage.
    $karimNet = $byEmployee['BDG-000001']->firstWhere('component', 'Net pay');
    expect($karimNet['delta'])->toBe(2000.0)
        ->and($karimNet['delta_percent'])->toBe('+8.0%')
        ->and($karimNet['note'])->toBeNull();

    // Nadia: the change is shown, the percentage is deliberately blank, and the
    // reason is stated. A "+100%" here would top the report every month and bury
    // the person whose pay actually moved.
    $joinerNet = $byEmployee['BDG-000002']->firstWhere('component', 'Net pay');
    expect($joinerNet['delta'])->toBe(30000.0)
        ->and($joinerNet['delta_percent'])->toBeNull()
        ->and($joinerNet['note'])->toBe('Joiner — not in previous run');
});

it('labels a leaver and a component that appeared or vanished, rather than inventing a percentage', function () {
    $previous = ($this->makeRun)(2026, 6);
    $current = ($this->makeRun)(2026, 7);

    $leaver = ($this->makeEmployee)('BDG-000003', 'Rahim');
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');

    ($this->makePayslip)($previous, $leaver, ['BASIC' => ['label' => 'Basic', 'amount' => 20000]], 20000);

    ($this->makePayslip)($previous, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 25000]], 25000);
    ($this->makePayslip)($current, $karim, [
        'BASIC' => ['label' => 'Basic', 'amount' => 25000],
        'LOAN' => ['label' => 'Loan installment', 'type' => 'deduction', 'amount' => 5000],
    ], 25000);

    $rows = collect(($this->rows)(app(PayrollVarianceReport::class), [
        'run_id' => $current->id, 'compare_run_id' => $previous->id, 'movements_only' => true,
    ]));

    expect($rows->firstWhere('employee_code', 'BDG-000003')['note'])
        ->toBe('Leaver — not in this run');

    // A component that did not exist last month is "added", not a 100% change —
    // Karim himself was in both runs, so the employee-level label does not apply.
    $loan = $rows->first(fn ($r) => $r['component'] === 'Loan installment');

    expect($loan['note'])->toBe('Component added')
        ->and($loan['delta_percent'])->toBeNull()
        ->and($loan['delta'])->toBe(5000.0);
});

/*
|--------------------------------------------------------------------------
| #11 — Earnings and deductions breakdown
|--------------------------------------------------------------------------
*/

it('gives the breakdown one column per component the run actually used', function () {
    $run = ($this->makeRun)(2026, 7);
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');
    $rahim = ($this->makeEmployee)('BDG-000002', 'Rahim');

    ($this->makePayslip)($run, $karim, [
        'BASIC' => ['label' => 'Basic', 'amount' => 25000],
        'HRA' => ['label' => 'House Rent', 'amount' => 20000],
    ], 45000);

    // Rahim has no house rent — his cell must be a zero, not a gap, or the
    // column does not sum in a spreadsheet.
    ($this->makePayslip)($run, $rahim, [
        'BASIC' => ['label' => 'Basic', 'amount' => 18000],
    ], 18000);

    $definition = app(EarningsDeductionsBreakdownReport::class);
    $parameters = new ReportParameters(['run_id' => $run->id]);

    $keys = collect($definition->columns($parameters))->pluck('key')->all();

    expect($keys)->toContain('c_BASIC')->toContain('c_HRA')
        // A component nobody received gets no column at all.
        ->not->toContain('c_MEDICAL');

    $rows = ($this->rows)($definition, ['run_id' => $run->id]);

    expect($rows[0]['c_BASIC'])->toBe(25000.0)
        ->and($rows[0]['c_HRA'])->toBe(20000.0)
        ->and($rows[1]['c_BASIC'])->toBe(18000.0)
        ->and($rows[1]['c_HRA'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| #12 — Loan balance
|--------------------------------------------------------------------------
*/

it('reads the loan balance from the ledger sum without a query per loan', function () {
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');

    $loan = Loan::create([
        'business_id' => $this->business->id, 'employee_id' => $karim->id,
        'reference' => 'LOAN-000001', 'type' => 'loan', 'status' => 'active',
        'principal' => 60000, 'installment_amount' => 5000, 'installment_count' => 12,
        'start_year' => 2026, 'start_month' => 7, 'reason' => 'Family',
        'disbursed_on' => '2026-06-01', 'applied_at' => now(),
    ]);

    LoanLedgerEntry::create([
        'business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $karim->id,
        'entry_type' => LoanLedgerEntryType::Disbursement, 'amount' => 60000,
        'occurred_on' => '2026-06-01',
    ]);

    LoanLedgerEntry::create([
        'business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $karim->id,
        'entry_type' => LoanLedgerEntryType::Repayment, 'amount' => -5000,
        'occurred_on' => '2026-07-23',
    ]);

    $rows = ($this->rows)(app(LoanBalanceReport::class), ['state' => 'outstanding']);

    expect($rows)->toHaveCount(1)
        // The same figure Loan::outstanding() would give, reached in SQL.
        ->and($rows[0]['outstanding'])->toBe(55000.0)
        ->and($rows[0]['repaid'])->toBe(5000.0)
        ->and($rows[0]['reference'])->toBe('LOAN-000001');

    expect($loan->fresh()->outstanding())->toBe(55000.0);
});

/*
|--------------------------------------------------------------------------
| #14 — Unpaid salary
|--------------------------------------------------------------------------
*/

it('treats a failure as delivering nothing and a partial payment as still owing', function () {
    $run = ($this->makeRun)(2026, 7);
    $paid = ($this->makeEmployee)('BDG-000001', 'Karim');
    $partial = ($this->makeEmployee)('BDG-000002', 'Rahim');
    $failed = ($this->makeEmployee)('BDG-000003', 'Nadia');

    $paidSlip = ($this->makePayslip)($run, $paid, ['BASIC' => ['label' => 'Basic', 'amount' => 10000]], 10000);
    $partialSlip = ($this->makePayslip)($run, $partial, ['BASIC' => ['label' => 'Basic', 'amount' => 10000]], 10000);
    $failedSlip = ($this->makePayslip)($run, $failed, ['BASIC' => ['label' => 'Basic', 'amount' => 10000]], 10000);

    $pay = fn (Payslip $slip, float $amount, PaymentEventType $type, ?string $reason = null) => PayslipPayment::create([
        'business_id' => $this->business->id,
        'payslip_id' => $slip->id, 'employee_id' => $slip->employee_id,
        'event_type' => $type, 'method' => 'bank_transfer',
        'amount' => $amount, 'paid_on' => '2026-07-25', 'failure_reason' => $reason,
    ]);

    $pay($paidSlip, 10000, PaymentEventType::Paid);
    $pay($partialSlip, 4000, PaymentEventType::Paid);
    // ⚠ A failure moves nothing. Counted as a negative it would show 20,000
    // outstanding against a 10,000 payslip.
    $pay($failedSlip, 10000, PaymentEventType::Failed, 'Account closed');

    $rows = collect(($this->rows)(app(UnpaidSalaryReport::class), [
        'run_id' => $run->id, 'include_partial' => true,
    ]));

    // The fully paid payslip is not on an unpaid report at all.
    expect($rows->pluck('employee_code')->all())->toBe(['BDG-000002', 'BDG-000003']);

    expect($rows->firstWhere('employee_code', 'BDG-000002'))
        ->toMatchArray(['paid' => 4000.0, 'outstanding' => 6000.0, 'status' => 'Partially paid']);

    expect($rows->firstWhere('employee_code', 'BDG-000003'))
        ->toMatchArray(['paid' => 0.0, 'outstanding' => 10000.0, 'status' => 'Unpaid', 'failure_reason' => 'Account closed']);
});

/*
|--------------------------------------------------------------------------
| #13 — Cost allocation, and the re-expressed accounting export
|--------------------------------------------------------------------------
*/

it('groups cost by department and by component without splitting it between them', function () {
    $run = ($this->makeRun)(2026, 7);
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');
    $rahim = ($this->makeEmployee)('BDG-000002', 'Rahim');

    ($this->makePayslip)($run, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 25000]], 25000);
    ($this->makePayslip)($run, $rahim, ['BASIC' => ['label' => 'Basic', 'amount' => 18000]], 18000);

    $rows = ($this->rows)(app(CostAllocationReport::class), ['run_id' => $run->id, 'grain' => 'component']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['department'])->toBe('Production')
        ->and($rows[0]['component'])->toBe('Basic')
        ->and((float) $rows[0]['amount'])->toBe(43000.0)
        ->and($rows[0]['employees'])->toBe(2);

    // ⚠ Caught in the live check: payslip lines are all stored positive and the
    // type decides the side, so totalling `amount` adds deductions to earnings.
    // The first live run footed to 111,200 against a payroll whose net was
    // 101,200 — a plausible figure matching nothing. `net_amount` carries the
    // sign, and it is the only column that totals.
    $withDeduction = ($this->makeRun)(2026, 8);
    $someone = ($this->makeEmployee)('BDG-000009', 'Nasrin');

    ($this->makePayslip)($withDeduction, $someone, [
        'BASIC' => ['label' => 'Basic', 'amount' => 20000],
        'LOAN' => ['label' => 'Loan Repayment', 'type' => 'deduction', 'amount' => 5000],
    ], 15000);

    $mixed = ($this->rows)(app(CostAllocationReport::class), ['run_id' => $withDeduction->id, 'grain' => 'run']);
    $totals = collect($mixed);

    expect((float) $totals->sum('net_amount'))->toBe(15000.0)
        ->and((float) $totals->sum('amount'))->toBe(25000.0)
        ->and(app(CostAllocationReport::class)->totalColumns())->toBe(['net_amount']);

    // The whole-run grain is DIS-05's accounting summary: no organisational
    // columns at all.
    $summary = ($this->rows)(app(CostAllocationReport::class), ['run_id' => $run->id, 'grain' => 'run']);

    expect($summary[0])->not->toHaveKey('department')
        ->and((float) $summary[0]['amount'])->toBe(43000.0);
});

it('resolves the accounting detail as at the period, not as at today', function () {
    // ⚠ The defect the re-expression fixed. The old AccountingExport built each
    // row from currentAssignment(), so re-running a CLOSED payroll's export
    // after a transfer silently refiled the whole month's cost — and the file
    // still footed.
    $run = ($this->makeRun)(2026, 7);
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');

    ($this->makePayslip)($run, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 25000]], 25000);

    // Karim transfers out after the period closed on 23 July.
    $packaging = Department::create([
        'name' => 'Packaging', 'office_id' => $this->office->id, 'is_active' => true,
    ]);
    EmployeeAssignment::where('employee_id', $karim->id)->update(['effective_to' => '2026-07-31']);
    EmployeeAssignment::create([
        'employee_id' => $karim->id, 'office_id' => $this->office->id,
        'department_id' => $packaging->id, 'is_primary' => true, 'effective_from' => '2026-08-01',
    ]);

    $rows = ($this->rows)(app(PayrollLineDetailReport::class), ['run_id' => $run->id]);

    expect($rows[0]['department'])->toBe('Production')
        ->and($rows[0]['formula'])->toBe('Fixed');
});

/*
|--------------------------------------------------------------------------
| #15 — Settlement worksheet (Correction C10b)
|--------------------------------------------------------------------------
*/

it('gathers what a leaver is owed and computes no final settlement figure', function () {
    $leaver = ($this->makeEmployee)('BDG-000001', 'Karim', [
        'separation_type' => 'resignation',
        'separation_date' => '2026-07-20',
    ]);

    $run = ($this->makeRun)(2026, 7);
    ($this->makePayslip)($run, $leaver, ['BASIC' => ['label' => 'Basic', 'amount' => 25000]], 25000);

    $loan = Loan::create([
        'business_id' => $this->business->id, 'employee_id' => $leaver->id,
        'reference' => 'LOAN-000001', 'type' => 'loan', 'status' => 'active',
        'principal' => 30000, 'installment_amount' => 5000, 'installment_count' => 6,
        'start_year' => 2026, 'start_month' => 7, 'reason' => 'Family', 'applied_at' => now(),
    ]);

    LoanLedgerEntry::create([
        'business_id' => $this->business->id, 'loan_id' => $loan->id, 'employee_id' => $leaver->id,
        'entry_type' => LoanLedgerEntryType::Disbursement, 'amount' => 30000, 'occurred_on' => '2026-06-01',
    ]);

    // C6a: only an encashable type counts towards a settlement.
    $encashable = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Earned leave', 'code' => 'EL',
        'annual_quota' => 20, 'can_encash' => true,
    ]);

    $notEncashable = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Sick leave', 'code' => 'SL',
        'annual_quota' => 14, 'can_encash' => false,
    ]);

    LeaveLedgerEntry::create([
        'business_id' => $this->business->id, 'employee_id' => $leaver->id,
        'leave_type_id' => $encashable->id, 'year' => 2026, 'entry_type' => 'grant',
        'days' => 12, 'occurred_on' => '2026-01-01',
    ]);

    LeaveLedgerEntry::create([
        'business_id' => $this->business->id, 'employee_id' => $leaver->id,
        'leave_type_id' => $notEncashable->id, 'year' => 2026, 'entry_type' => 'grant',
        'days' => 14, 'occurred_on' => '2026-01-01',
    ]);

    $definition = app(SettlementWorksheetReport::class);
    $rows = ($this->rows)($definition, ['outstanding_only' => true]);

    expect($rows)->toHaveCount(1);

    $row = $rows[0];

    expect($row['separation_type'])->toBe('Resignation')
        ->and($row['unpaid_salary'])->toBe(25000.0)
        ->and($row['loan_outstanding'])->toBe(30000.0)
        // Days, and only the encashable type's — the sick leave is not here.
        ->and($row['encashable_leave_days'])->toBe(12.0)
        ->and($row['outstanding_items'])->toBe('Unpaid salary, loan balance, leave encashment');

    // ⚠⚠ C10b, asserted rather than merely documented: there is no settlement
    // total on this report and there must not be one until the encashment rate
    // is settled. A column added here later would be paid to somebody.
    $columns = collect($definition->columns())->pluck('key');

    expect($columns)->not->toContain('settlement_total')
        ->and($columns)->not->toContain('total')
        ->and($definition->totalColumns())->not->toContain('settlement_total');

    expect($definition->meta(new ReportParameters([]))->lockNote)
        ->toContain('computes NO final settlement figure');
});

/*
|--------------------------------------------------------------------------
| All of RPT-02, through the framework
|--------------------------------------------------------------------------
*/

it('serves every new payroll report over HTTP in all four formats', function () {
    $run = ($this->makeRun)(2026, 7);
    $previous = ($this->makeRun)(2026, 6);
    $karim = ($this->makeEmployee)('BDG-000001', 'Karim');

    ($this->makePayslip)($run, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 25000]], 25000);
    ($this->makePayslip)($previous, $karim, ['BASIC' => ['label' => 'Basic', 'amount' => 24000]], 24000);

    $reports = [
        'payroll_variance' => ['run_id' => $run->id, 'compare_run_id' => $previous->id],
        'payroll_component_breakdown' => ['run_id' => $run->id],
        'payroll_loan_balance' => [],
        'payroll_cost_allocation' => ['run_id' => $run->id],
        'payroll_unpaid_salary' => ['run_id' => $run->id],
        'payroll_line_detail' => ['run_id' => $run->id],
        'payroll_settlement_worksheet' => [],
    ];

    foreach ($reports as $key => $parameters) {
        foreach (['csv', 'xlsx', 'pdf', 'print'] as $format) {
            $query = http_build_query([...$parameters, 'format' => $format]);

            $this->actingAs($this->hr)
                ->get(route('hrm.reports.run', $key).'?'.$query)
                ->assertOk();
        }
    }
});

it('registers fourteen of the fifteen reports, with only the bank advice missing', function () {
    // Where the RPT-01/02 list stands after W3c. The plan's audit called twelve
    // of fifteen buildable; C10c defined #10, the C7c note settled #13 and C10b
    // turned #15 into a worksheet, so fourteen are shipped. **Only #9, the bank
    // advice, is still absent — C9d blocks it for want of a client sample.**
    //
    // Pinning it here means a later slice cannot quietly add an invented bank
    // format, and cannot quietly drop the worksheet either.
    $keys = app(ReportRegistry::class)->all()->keys();

    expect($keys)->not->toContain('payroll_bank_advice')
        ->and($keys)->toContain('payroll_settlement_worksheet')
        // W2's reconciliation is registered too (Correction C16). It is not one
        // of the numbered fifteen — RPT-02 never asked for it; ROL-02 did, as
        // the go-live acceptance artefact.
        ->and($keys)->toContain('parallel_run_reconciliation')
        // Fourteen of the numbered fifteen, plus the accounting detail that
        // AccountingExport was re-expressed onto, plus W2's reconciliation.
        //
        // ⚠ The count is deliberate, not incidental: it is what stops a later
        // slice quietly adding an invented bank format. Raise it only when you
        // meant to add a report, and say which one here.
        //
        // 17: the meal break compliance report (2026-08-17). C24 keeps the meal
        // engine free of consequences, so THIS report is where overruns and
        // late returns are counted for any show-cause decision.
        ->and($keys)->toContain('attendance_meal_compliance')
        ->and($keys)->toHaveCount(17);
});
