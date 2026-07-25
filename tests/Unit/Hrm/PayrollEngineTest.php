<?php

use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Payroll\Data\AttendanceFacts;
use App\Modules\Hrm\Payroll\Data\EmployeePayrollContext;
use App\Modules\Hrm\Payroll\Data\PayrollInput;
use App\Modules\Hrm\Payroll\Data\PayrollPeriodSpec;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Payroll\PayrollEngine;
use App\Modules\Hrm\Salary\ResolvedSalaryLine;
use App\Modules\Hrm\Salary\SalaryBreakdown;

/**
 * The payroll engine is pure, so every rule below runs from fixtures with no
 * database at all — the same discipline the attendance engine follows.
 *
 * The first test is the architectural anchor: PAY-13, the client's own
 * approved worked example. The engine exists to reproduce it.
 */

/**
 * PAY-13's salary structure: Gross 50,000 made of Basic 25,000 plus House Rent
 * 20,000 and Medical 5,000. Built as the pure value object Phase 3a produces,
 * because that is exactly what payroll snapshots at cutoff.
 */
function payBreakdown(float $basic = 25000, float $houseRent = 20000, float $medical = 5000): SalaryBreakdown
{
    $earning = fn (int $id, string $code, string $name, float $amount, bool $isBasic = false) => new ResolvedSalaryLine(
        componentId: $id,
        code: $code,
        name: $name,
        type: SalaryComponentType::Earning,
        basis: SalaryCalculationBasis::Fixed,
        isBasic: $isBasic,
        isStatutory: false,
        value: $amount,
        amount: $amount,
        formula: number_format($amount, 2),
    );

    $lines = [
        $earning(1, 'basic', 'Basic', $basic, true),
        $earning(2, 'house_rent', 'House Rent', $houseRent),
        $earning(3, 'medical', 'Medical Allowance', $medical),
    ];

    return new SalaryBreakdown($lines, $basic, round($basic + $houseRent + $medical, 2), 0.0);
}

function payFacts(array $overrides = []): AttendanceFacts
{
    return new AttendanceFacts(
        presentDays: $overrides['present'] ?? 25,
        absentDays: $overrides['absent'] ?? 1,
        paidLeaveDays: $overrides['paid_leave'] ?? 2,
        unpaidLeaveDays: $overrides['unpaid_leave'] ?? 0,
        lateOccurrences: $overrides['late_occurrences'] ?? 4,
        lateMinutes: $overrides['late_minutes'] ?? 38,
        // POL-03's consequence is decided by Attendance and consumed here as a
        // fact. Four late arrivals are a warning only, so nothing is priced.
        deductibleDays: $overrides['deductible_days'] ?? 0.0,
        hasUnresolvedMissingPunch: $overrides['missing_punch'] ?? false,
    );
}

function payContext(array $overrides = []): EmployeePayrollContext
{
    return new EmployeePayrollContext(
        employeeId: 1,
        period: $overrides['period'] ?? PayrollPeriodSpec::forMonth(2026, 7),
        salary: $overrides['salary'] ?? payBreakdown(),
        attendance: $overrides['attendance'] ?? payFacts(),
        overtimeHours: $overrides['overtime_hours'] ?? 8.0,
        allowances: $overrides['allowances'] ?? [
            PayrollInput::earning('attendance_allowance', 'Attendance Allowance', 2000.00),
        ],
        loanInstallments: $overrides['loans'] ?? [],
        adjustments: $overrides['adjustments'] ?? [],
        config: $overrides['config'] ?? PayrollConfig::defaults(),
        joiningDate: $overrides['joining'] ?? null,
        separationDate: $overrides['separation'] ?? null,
    );
}

function payRun(array $overrides = [])
{
    return (new PayrollEngine)->process(payContext($overrides));
}

// =============================================================================
// THE ACCEPTANCE TEST — PAY-13, the client's approved worked example.
// =============================================================================

it('reproduces the PAY-13 worked example exactly', function () {
    // Inputs, verbatim from the questionnaire:
    //   Gross 50,000 · Basic 25,000 · July 2026
    //   Present 25 · Absent 1 · Paid leave 2 · Unpaid leave 0
    //   Late 4 times / 38 minutes · Overtime 8 hours
    //   Attendance Allowance 2,000 · Loan 0
    //
    // The client's stated net is 51,200. ADJ-02 (the overtime hourly rate) was
    // left blank, and no formula it offered produces that figure. Correction
    // C7a settles the rate as configurable data seeded Basic ÷ 30 ÷ 8, which
    // gives 51,166.66 — every step traceable to a ticked rule. The 33.34
    // difference is an open question for the client, not a number to engineer
    // backwards into the engine.
    $payslip = payRun();

    expect($payslip->net)->toBe(51166.66);
});

it('shows its work for every figure in PAY-13', function () {
    $payslip = payRun();

    // Gross comes from the salary structure, untouched by the month.
    expect($payslip->grossSalary)->toBe(50000.00);

    // PAY-03: absence is deducted from Gross, PAY-02's divisor is a fixed 30.
    $absent = $payslip->line('absent');
    expect($absent->amount)->toBe(1666.67);
    expect($absent->quantity)->toBe(1.0);
    expect($absent->formula)->toContain('50,000.00 ÷ 30');

    // PAY-08: two days of paid leave cost nothing.
    expect($payslip->hasLine('lwp'))->toBeFalse();

    // POL-03 / PAY-07: four late arrivals are a warning, not money.
    expect($payslip->hasLine('late_early'))->toBeFalse();

    // ADJ-02 via Correction C7a: Basic ÷ 30 ÷ 8 = 104.1667/hour.
    $overtime = $payslip->line('overtime');
    expect($overtime->quantity)->toBe(8.0);
    expect($overtime->amount)->toBe(833.33);
    expect($overtime->formula)->toContain('25,000.00 ÷ 30 ÷ 8');

    expect($payslip->line('attendance_allowance')->amount)->toBe(2000.00);

    // 50,000.00 + 2,000.00 + 833.33 = 52,833.33 earned; 1,666.67 deducted.
    expect($payslip->totalEarnings)->toBe(52833.33);
    expect($payslip->totalDeductions)->toBe(1666.67);
    expect($payslip->net)->toBe(51166.66);
});

it('rounds each component rather than only the total, as PAY-10 requires', function () {
    $payslip = payRun();

    // Rounding only at the end would give 51,166.67 — a paisa adrift. PAY-10
    // is explicit that each component carries 2 decimals.
    expect($payslip->line('absent')->amount)->toBe(1666.67);
    expect($payslip->line('overtime')->amount)->toBe(833.33);
    expect($payslip->net)->toBe(51166.66);
});

// =============================================================================
// The rules the anchor exercises, tested in isolation.
// =============================================================================

it('runs the payroll month from the 24th to the 23rd', function () {
    // Correction C7b. July 2026 covers 24 June to 23 July.
    $period = PayrollPeriodSpec::forMonth(2026, 7);

    expect($period->start->toDateString())->toBe('2026-06-24');
    expect($period->end->toDateString())->toBe('2026-07-23');
    // PAY-02: the divisor is a fixed 30, not the number of days in the range.
    expect($period->divisorDays)->toBe(30);
});

it('deducts absence from gross using the fixed 30-day divisor', function () {
    $payslip = payRun(['attendance' => payFacts(['absent' => 3])]);

    // 50,000 ÷ 30 × 3 = 5,000.00
    expect($payslip->line('absent')->amount)->toBe(5000.00);
});

it('charges unpaid leave on the same formula as absence', function () {
    // PAY-04 ticked "same formula as absent".
    $payslip = payRun(['attendance' => payFacts(['absent' => 0, 'unpaid_leave' => 2])]);

    expect($payslip->hasLine('absent'))->toBeFalse();
    expect($payslip->line('lwp')->amount)->toBe(3333.33);
});

it('never deducts for paid leave', function () {
    // PAY-08.
    $withLeave = payRun(['attendance' => payFacts(['absent' => 0, 'paid_leave' => 10])]);
    $without = payRun(['attendance' => payFacts(['absent' => 0, 'paid_leave' => 0])]);

    expect($withLeave->net)->toBe($without->net);
});

it('prices the attendance consequence rather than deciding it', function () {
    // Attendance owns POL-03 and emits deductible days as a fact; payroll only
    // turns that fact into money.
    $payslip = payRun(['attendance' => payFacts(['absent' => 0, 'deductible_days' => 1.0])]);

    expect($payslip->line('late_early')->amount)->toBe(1666.67);
    expect($payslip->line('late_early')->formula)->toContain('÷ 30');
});

it('prices overtime from the configured rate', function () {
    $payslip = payRun(['overtime_hours' => 10.0]);

    // 25,000 ÷ 30 ÷ 8 × 10 = 1,041.67
    expect($payslip->line('overtime')->amount)->toBe(1041.67);
});

it('lets the overtime rate be reconfigured without touching the engine', function () {
    // Correction C7a: the rate is data. If the client confirms a different
    // formula, this is the only thing that changes.
    $onGross = payRun(['config' => PayrollConfig::defaults(overtimeRateBase: 'gross')]);

    // 50,000 ÷ 30 ÷ 8 × 8 = 1,666.67
    expect($onGross->line('overtime')->amount)->toBe(1666.67);
});

it('caps overtime at the monthly maximum', function () {
    // ADJ-03: monthly maximum 60 hours.
    $payslip = payRun(['overtime_hours' => 80.0]);

    expect($payslip->line('overtime')->quantity)->toBe(60.0);
});

it('ignores an overtime stint below the eligible minimum', function () {
    // ADJ-03: minimum eligible 30 minutes.
    $payslip = payRun(['overtime_hours' => 0.25]); // 15 minutes

    expect($payslip->hasLine('overtime'))->toBeFalse();
});

it('rounds overtime to the configured unit', function () {
    // ADJ-03: rounding unit 30 minutes. 2h50m rounds down to 2h30m.
    $payslip = payRun(['overtime_hours' => 2.8333]);

    expect($payslip->line('overtime')->quantity)->toBe(2.5);
});

// =============================================================================
// Proration, blocking and the negative-net guard.
// =============================================================================

it('prorates a mid-period joiner by calendar days', function () {
    // PAY-05: proration runs from the joining date on calendar days.
    $payslip = payRun([
        'joining' => '2026-07-09',
        'attendance' => payFacts(['absent' => 0, 'paid_leave' => 0]),
        'overtime_hours' => 0.0,
        'allowances' => [],
    ]);

    // Joined 9 July, period ends 23 July: 15 of the 30 divisor days.
    expect($payslip->line('proration')->quantity)->toBe(15.0);
    expect($payslip->totalDeductions)->toBe(25000.00);
    expect($payslip->net)->toBe(25000.00);
});

it('pays a leaver only to their last working day', function () {
    // PAY-06.
    $payslip = payRun([
        'separation' => '2026-07-08',
        'attendance' => payFacts(['absent' => 0, 'paid_leave' => 0]),
        'overtime_hours' => 0.0,
        'allowances' => [],
    ]);

    // 24 June to 8 July inclusive is 15 days.
    expect($payslip->line('proration')->quantity)->toBe(15.0);
});

it('blocks a payslip while a missing punch is unresolved', function () {
    // PAY-09: the employee's line is blocked, not guessed at.
    $payslip = payRun(['attendance' => payFacts(['missing_punch' => true])]);

    expect($payslip->isBlocked)->toBeTrue();
    expect(implode(' ', $payslip->blockReasons))->toContain('missing punch');
});

it('blocks a negative net rather than paying one', function () {
    // PAY-11: an automatic approval block, resolved by a human.
    $payslip = payRun([
        'attendance' => payFacts(['absent' => 0]),
        'adjustments' => [PayrollInput::deduction('recovery', 'Recovery', 80000.00)],
    ]);

    expect($payslip->net)->toBeLessThan(0);
    expect($payslip->isBlocked)->toBeTrue();
    expect(implode(' ', $payslip->blockReasons))->toContain('Negative');
});

it('applies loan installments and adjustments as their own lines', function () {
    $payslip = payRun([
        'attendance' => payFacts(['absent' => 0]),
        'overtime_hours' => 0.0,
        'allowances' => [],
        'loans' => [PayrollInput::deduction('loan_repayment', 'Loan Repayment', 5000.00)],
        'adjustments' => [PayrollInput::earning('reimbursement', 'Travel Reimbursement', 1200.00)],
    ]);

    // Amounts are always positive; the line's type says which side it falls on.
    expect($payslip->line('loan_repayment')->amount)->toBe(5000.00);
    expect($payslip->line('loan_repayment')->isDeduction())->toBeTrue();
    expect($payslip->line('reimbursement')->amount)->toBe(1200.00);
    expect($payslip->net)->toBe(46200.00);
});

// =============================================================================
// Determinism and provenance.
// =============================================================================

it('produces the same payslip every time from the same inputs', function () {
    $context = payContext();

    $first = (new PayrollEngine)->process($context);
    $second = (new PayrollEngine)->process($context);

    expect($first->net)->toBe($second->net);
    expect($first->toArray())->toBe($second->toArray());
});

it('carries a formula and a source on every line', function () {
    $payslip = payRun();

    foreach ($payslip->lines as $line) {
        expect($line->formula)->not->toBeEmpty();
        expect($line->source)->not->toBeEmpty();
    }
});

it('explains the whole payslip in its notes', function () {
    $payslip = payRun();

    $explanation = implode(' ', $payslip->notes);

    expect($explanation)->toContain('24 June');
    expect($explanation)->toContain('30');
});
