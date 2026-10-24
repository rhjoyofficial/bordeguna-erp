<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Hrm\Actions\CancelPayrollAdjustment;
use App\Modules\Hrm\Actions\CancelStaffLoan;
use App\Modules\Hrm\Actions\PostLoanDisbursement;
use App\Modules\Hrm\Corrections\PayrollAdjustmentActionPolicy;
use App\Modules\Hrm\Corrections\StaffLoanActionPolicy;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\LoanInstallment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CorrectionWorld;
use Tests\Support\PeopleWorld;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): payroll adjustment Cancel and staff
 * loan Cancel before the first instalment (FINAL-PLAN 3.4).
 *
 * ⛔ A locked or paid payroll is never touched (August 2026 is locked at net
 * 230,805): a cancel in a closed month writes an opposite line in a later one.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->p = PeopleWorld::make($this->w);
});

afterEach(fn () => Carbon::setTestNow());

function runFor($period, PayrollStatus $status): PayrollRun
{
    return PayrollRun::create(['business_id' => test()->w->business->id, 'payroll_period_id' => $period->id, 'version' => 1, 'status' => $status]);
}

// ─── payroll adjustments ────────────────────────────────────────────────

it('drops an approved adjustment from a month that still takes inputs', function () {
    $adjustment = $this->p->approvedAdjustment($this->p->period(2026, 11));
    expect(PayrollAdjustment::payable()->count())->toBe(1);

    $this->actingAs($this->w->financeB)
        ->post(route('hrm.adjustments.cancel', $adjustment), ['reason_text' => 'Wrong employee picked'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $adjustment->refresh();

    expect($adjustment->isCancelled())->toBeTrue()
        ->and($adjustment->cancel_offset_id)->toBeNull()
        ->and($adjustment->cancel_reason)->toContain('Wrong employee picked')
        ->and(PayrollAdjustment::payable()->count())->toBe(0)
        ->and(PayrollAdjustment::count())->toBe(1);
});

it('frees a generated line\'s source key when it is dropped, so a corrected source can make it again', function () {
    $arrear = $this->p->generated($this->p->period(2026, 11));
    $key = $arrear->source_key;

    app(CancelPayrollAdjustment::class)->handle($arrear, $this->w->financeB, $this->p->reason());

    expect($arrear->fresh()->source_key)->toBe("{$key}|cancelled:{$arrear->id}")
        ->and(PayrollAdjustment::once($key, ['business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id, 'payroll_period_id' => $arrear->payroll_period_id, 'type' => PayrollAdjustmentType::Arrear, 'amount' => '1000', 'reason' => 'Again', 'requested_by' => $this->w->owner->id]))->not->toBeNull();
});

it('never touches a locked month: an opposite line goes on the next month that takes inputs', function () {
    $august = $this->p->period(2026, 8);
    $run = runFor($august, PayrollStatus::Locked);
    $payslip = Payslip::create(['business_id' => $this->w->business->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->p->employee->id, 'gross_salary' => 20000, 'total_earnings' => 20800, 'total_deductions' => 0, 'net' => 20800]);
    $addition = $this->p->approvedAdjustment($august, PayrollAdjustmentType::Addition, '800');
    $before = [$payslip->fresh()->toArray(), $run->fresh()->toArray()];

    $plan = app(PayrollAdjustmentActionPolicy::class)->availability($addition, $this->w->financeB, DocumentAction::Cancel);
    expect($plan->allowed)->toBeTrue()
        ->and($plan->date->sentence)->toContain('opposite Deduction of Tk 800 goes on the September 2026 payroll');

    app(CancelPayrollAdjustment::class)->handle($addition, $this->w->financeB, $this->p->reason('Night duty was not done'));

    $addition->refresh();
    $offset = $addition->cancelOffset;

    expect($addition->isCancelled())->toBeTrue()
        ->and($offset->type)->toBe(PayrollAdjustmentType::Deduction)
        ->and((string) $offset->amount)->toBe('800.00')
        ->and($offset->period->label())->toBe('September 2026')
        ->and($offset->isGenerated())->toBeTrue()
        ->and(PayrollAdjustment::payable()->pluck('id')->all())->toBe([$offset->id])
        // ⛔ The locked run and its payslip are exactly as they were.
        ->and([$payslip->fresh()->toArray(), $run->fresh()->toArray()])->toBe($before);
});

it('sends a line that pays an expense claim to the claim, refuses a second cancel, and the Manager cannot cancel', function () {
    $claim = $this->p->onSalary($this->p->approvedClaim('400'), $this->p->period(2026, 11));
    $line = PayrollAdjustment::findOrFail($claim->payroll_adjustment_id);
    $policy = app(PayrollAdjustmentActionPolicy::class);

    expect(collect($policy->availability($line, $this->w->financeB, DocumentAction::Cancel)->blockers)->pluck('message')->all())
        ->toContain("This line pays expense claim {$claim->claim_number}, so use Reverse payment on the claim.");

    $other = $this->p->approvedAdjustment($this->p->period(2026, 11));
    $this->actingAs($this->w->manager)->post(route('hrm.adjustments.cancel', $other), ['reason_text' => 'Not mine to cancel'])->assertForbidden();

    app(CancelPayrollAdjustment::class)->handle($other, $this->w->financeB, $this->p->reason());
    expect(fn () => app(CancelPayrollAdjustment::class)->handle($other, $this->w->financeB, $this->p->reason()))->toThrow(ValidationException::class);

    $this->actingAs($this->w->financeB)->postJson(route('hrm.adjustments.cancel', $line), ['reason_text' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason_text');
});

// ─── staff loans ────────────────────────────────────────────────────────

it('the Owner cancels a loan before its first instalment: payout reversed, ledger zero, schedule cancelled', function () {
    $loan = $this->p->disbursedLoan('6000', '2026-10-10');
    expect($this->p->balance('employee_advances'))->toBe(600000);

    $this->actingAs($this->w->owner)
        ->post(route('hrm.loans.cancel', $loan), ['reason_text' => 'Employee changed his mind'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $loan->refresh();
    $reversal = Journal::findOrFail($loan->cancel_journal_id);

    expect($loan->status)->toBe(LoanStatus::Cancelled)
        ->and($loan->outstanding())->toBe(0.0)
        ->and($reversal->journal_date->toDateString())->toBe('2026-10-10')
        ->and(LoanInstallment::where('loan_id', $loan->id)->where('status', '!=', LoanInstallmentStatus::Cancelled)->count())->toBe(0)
        ->and($this->p->balance('employee_advances'))->toBe(0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);

    expect(fn () => app(CancelStaffLoan::class)->handle($loan, $this->w->owner, $this->p->reason()))->toThrow(ValidationException::class);
});

it('only the Owner cancels a loan', function () {
    $loan = $this->p->disbursedLoan();

    $this->actingAs($this->w->financeB)
        ->post(route('hrm.loans.cancel', $loan), ['reason_text' => 'Employee changed his mind'])
        ->assertSessionHasErrors('reason_text');

    expect($loan->fresh()->status)->toBe(LoanStatus::Active);
});

it('refuses a loan whose recovery started, whose payout is bank-matched, or whose instalment is on a closed payroll', function () {
    $policy = app(StaffLoanActionPolicy::class);
    $codes = fn ($loan) => collect($policy->availability($loan->fresh(), $this->w->owner, DocumentAction::Cancel)->blockers)->pluck('code')->all();

    $recovered = $this->p->disbursedLoan();
    $recovered->installments()->first()->update(['status' => LoanInstallmentStatus::Paid]);
    expect($codes($recovered))->toContain('recovery_started');

    $matched = $this->p->disbursedLoan();
    $line = app(PostLoanDisbursement::class)->existingJournal($matched)->lines()->where('amount_minor', '<', 0)->firstOrFail();
    $this->p->matchLine($line->id, '2026-10-10', '6000');
    expect($codes($matched))->toContain('bank_matched');

    $onPayroll = $this->p->disbursedLoan();
    $run = runFor($this->p->period(2026, 11), PayrollStatus::Approved);
    $payslip = Payslip::create(['business_id' => $this->w->business->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->p->employee->id, 'gross_salary' => 20000, 'total_earnings' => 20000, 'total_deductions' => 2000, 'net' => 18000]);
    PayslipLine::create(['payslip_id' => $payslip->id, 'code' => 'loan_repayment', 'label' => 'Advance', 'type' => 'deduction', 'amount' => 2000, 'quantity' => 1, 'rate' => 2000, 'formula' => 'installment', 'source' => 'loan_installment:'.$onPayroll->installments()->first()->id, 'sequence' => 1]);
    expect($codes($onPayroll))->toContain('on_closed_payroll');

    // A payroll that can still be recalculated allows it, and says to recalculate.
    $run->update(['status' => PayrollStatus::Calculated]);
    $availability = $policy->availability($onPayroll->fresh(), $this->w->owner, DocumentAction::Cancel);
    expect($availability->allowed)->toBeTrue()
        ->and($availability->date->sentence)->toContain('Recalculate the November 2026 payroll');
});

it('in a closed month the loan cancel lands on the first open day', function () {
    $loan = $this->p->disbursedLoan('6000', '2026-10-10');
    Carbon::setTestNow('2026-11-05 10:00:00');
    $this->w->closeThrough('2026-10-31');

    app(CancelStaffLoan::class)->handle($loan, $this->w->owner, $this->p->reason());

    expect(Journal::findOrFail($loan->fresh()->cancel_journal_id)->journal_date->toDateString())->toBe('2026-11-01');
});

it('registers both types', function () {
    $registry = app(DocumentPolicyRegistry::class);

    expect($registry->has(PayrollAdjustmentActionPolicy::TYPE))->toBeTrue()
        ->and($registry->has(StaffLoanActionPolicy::TYPE))->toBeTrue();
});
