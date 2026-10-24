<?php

use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollAdjustment;
use Illuminate\Support\Carbon;
use Tests\Support\CorrectionWorld;
use Tests\Support\PeopleWorld;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a payroll adjustment or a
 * staff loan is edited in place while it waits for its first approval, with
 * the same checks as raising it; once approved it is not.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->p = PeopleWorld::make($this->w);

    $this->pendingAdjustment = function (): PayrollAdjustment {
        $adjustment = PayrollAdjustment::create([
            'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id, 'payroll_period_id' => $this->p->period(2026, 11)->id,
            'type' => PayrollAdjustmentType::Addition, 'amount' => '800', 'reason' => 'Night duty', 'requested_by' => $this->w->financeA->id,
        ]);
        $adjustment->submitForApproval($this->w->financeA);

        return $adjustment->fresh();
    };

    $this->pendingLoan = function (): Loan {
        $loan = Loan::create([
            'business_id' => $this->w->business->id, 'employee_id' => $this->p->employee->id, 'reference' => 'LOAN-0001', 'type' => LoanType::Advance,
            'status' => LoanStatus::Pending, 'principal' => '6000', 'installment_amount' => '2000', 'installment_count' => 3,
            'start_year' => 2026, 'start_month' => 11, 'reason' => 'Medical', 'requested_by' => $this->w->financeA->id,
        ]);
        $loan->submitForApproval($this->w->financeA);

        return $loan->fresh();
    };
});

afterEach(fn () => Carbon::setTestNow());

it('edits a waiting adjustment in place and writes the change to its History', function () {
    $adjustment = ($this->pendingAdjustment)();
    $december = $this->p->period(2026, 12);

    $this->actingAs($this->w->financeB)->put(route('hrm.adjustments.update', $adjustment), [
        'type' => 'deduction', 'amount' => '650', 'payroll_period_id' => $december->id, 'reason' => 'Late fine, not night duty',
    ])->assertSessionHasNoErrors();

    $adjustment->refresh();
    expect($adjustment->type)->toBe(PayrollAdjustmentType::Deduction)
        ->and((string) $adjustment->amount)->toBe('650.00')
        ->and($adjustment->payroll_period_id)->toBe($december->id)
        ->and($adjustment->approvalRequest->isPending())->toBeTrue();

    // An arrear is never typed by hand, and nothing is zero.
    $this->actingAs($this->w->financeB)->put(route('hrm.adjustments.update', $adjustment), [
        'type' => 'deduction', 'amount' => '0', 'payroll_period_id' => $december->id, 'reason' => 'Zero',
    ])->assertSessionHasErrors('amount');
});

it('refuses to edit an approved adjustment', function () {
    $adjustment = ($this->pendingAdjustment)();
    $adjustment->approvalRequest->approve($this->w->owner);

    $this->actingAs($this->w->financeB)->put(route('hrm.adjustments.update', $adjustment->fresh()), [
        'type' => 'addition', 'amount' => '900', 'payroll_period_id' => $adjustment->payroll_period_id, 'reason' => 'More night duty',
    ])->assertSessionHasErrors('amount');

    expect((string) $adjustment->fresh()->amount)->toBe('800.00');
});

it('edits a waiting loan: the schedule follows the new instalment', function () {
    $loan = ($this->pendingLoan)();

    $this->actingAs($this->w->sales)->put(route('hrm.loans.update', $loan), [
        'principal' => '9000', 'installment_amount' => '3000', 'start_year' => 2026, 'start_month' => 12, 'reason' => 'Medical, larger bill',
    ])->assertForbidden();

    $this->actingAs($this->w->financeB)->put(route('hrm.loans.update', $loan), [
        'principal' => '9000', 'installment_amount' => '3000', 'start_year' => 2026, 'start_month' => 12, 'reason' => 'Medical, larger bill',
    ])->assertSessionHasNoErrors();

    $loan->refresh();
    expect((string) $loan->principal)->toBe('9000.00')
        ->and((int) $loan->installment_count)->toBe(3)
        ->and((int) $loan->start_month)->toBe(12);

    $this->actingAs($this->w->financeB)->put(route('hrm.loans.update', $loan), [
        'principal' => '1000', 'installment_amount' => '3000', 'start_year' => 2026, 'start_month' => 12, 'reason' => 'Instalment above principal',
    ])->assertSessionHasErrors('installment_amount');
});

it('marks waiting rows editable on the screens', function () {
    ($this->pendingAdjustment)();
    ($this->pendingLoan)();

    $this->actingAs($this->w->financeB)->get(route('hrm.payroll.inputs.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('adjustments.0.editable', true));
    $this->actingAs($this->w->financeB)->get(route('hrm.loans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('loans.0.editable', true));
});
