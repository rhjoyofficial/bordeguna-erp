<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentPolicyRegistry;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Actions\CancelExpenseClaimApproval;
use App\Modules\Hrm\Actions\PostExpenseClaimApproval;
use App\Modules\Hrm\Actions\ReverseExpenseClaimPayment;
use App\Modules\Hrm\Actions\SettleExpenseClaim;
use App\Modules\Hrm\Corrections\ExpenseClaimActionPolicy;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CorrectionWorld;
use Tests\Support\PeopleWorld;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): an expense claim after approval
 * (FINAL-PLAN 3.4; HRM plan S1 and S2). Cancel approval mirrors the approval
 * journal and hands the claim back; Reverse payment (the Owner) undoes the
 * payment, by whichever of the three routes it was paid.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
    $this->p = PeopleWorld::make($this->w);
});

afterEach(fn () => Carbon::setTestNow());

it('cancels an approval on its own date, hands the claim back, and the books net to zero', function () {
    $claim = $this->p->approvedClaim('500');
    $approval = Journal::findOrFail($claim->approval_journal_id);

    $this->actingAs($this->w->financeB)
        ->post(route('hrm.expense-claims.cancel-approval', $claim), ['reason_text' => 'Typed 500 for 50'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $claim->refresh()->load('approvalRequest');
    $reversal = Journal::where('reverses_journal_id', $approval->id)->sole();

    expect($reversal->journal_date->toDateString())->toBe($approval->journal_date->toDateString())
        ->and($claim->approval_journal_id)->toBeNull()
        ->and($claim->isDraft())->toBeTrue()
        ->and($claim->wasSentBack())->toBeTrue()
        ->and($claim->state())->toBe('Sent back for correction')
        ->and($this->p->balance('employee_reimbursements_payable'))->toBe(0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0)
        ->and(Activity::where('log_name', 'corrections')->where('event', 'cancelled')->count())->toBe(1);

    // The employee fixes it and sends it again; the next approval posts afresh.
    $claim->forceFill(['submitted_at' => now()])->save();
    $claim->resubmitForApproval($this->p->staff)->approve($this->w->owner);
    app(PostExpenseClaimApproval::class)->handle($claim->fresh()->load('lines'), $this->w->owner);

    expect($claim->fresh()->approval_journal_id)->not->toBeNull()->not->toBe($approval->id)
        ->and($this->p->balance('employee_reimbursements_payable'))->toBe(-50000);
});

it('demands a reason of five characters on the server', function () {
    $claim = $this->p->approvedClaim();

    foreach ([[], ['reason_text' => 'ok'], ['reason_text' => '     ']] as $body) {
        $this->actingAs($this->w->owner)
            ->postJson(route('hrm.expense-claims.cancel-approval', $claim), $body)
            ->assertStatus(422)->assertJsonValidationErrors('reason_text');
    }

    expect($claim->fresh()->isApproved())->toBeTrue();
});

it('lets only the claim\'s approver cancel the approval', function () {
    $claim = $this->p->approvedClaim();

    $this->actingAs($this->w->manager)
        ->post(route('hrm.expense-claims.cancel-approval', $claim), ['reason_text' => 'Wrong amount typed'])
        ->assertForbidden();

    $availability = app(ExpenseClaimActionPolicy::class)->availability($claim, $this->w->sales, DocumentAction::Cancel);
    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code'))->toContain('not_approver');

    // The original's approver may cancel it too (Joy, 19 Oct: only a requester is refused).
    expect(app(ExpenseClaimActionPolicy::class)->availability($claim, $this->w->owner, DocumentAction::Cancel)->allowed)->toBeTrue();
});

it('refuses a second cancel of the same approval', function () {
    $claim = $this->p->approvedClaim();
    $cancel = app(CancelExpenseClaimApproval::class);

    $cancel->handle($claim, $this->w->owner, $this->p->reason());

    expect(fn () => $cancel->handle($claim, $this->w->owner, $this->p->reason()))->toThrow(ValidationException::class);
    expect(Journal::whereNotNull('reverses_journal_id')->count())->toBe(1);
});

it('in a closed month only the Owner cancels an approval, and it lands on the first open day', function () {
    $claim = $this->p->approvedClaim();
    Carbon::setTestNow('2026-11-05 10:00:00');
    $this->w->closeThrough('2026-10-31');

    $finance = app(ExpenseClaimActionPolicy::class)->availability($claim, $this->w->financeB, DocumentAction::Cancel);
    expect($finance->allowed)->toBeFalse()
        ->and(collect($finance->blockers)->pluck('code'))->toContain('period_closed_not_owner');

    app(CancelExpenseClaimApproval::class)->handle($claim, $this->w->owner, $this->p->reason());

    expect(Journal::whereNotNull('reverses_journal_id')->sole()->journal_date->toDateString())->toBe('2026-11-01');
});

it('paid now: Cancel approval says reverse the payment first; the Owner reverses the payment', function () {
    $claim = $this->p->paidNow($this->p->approvedClaim('700'));
    $policy = app(ExpenseClaimActionPolicy::class);

    expect(collect($policy->availability($claim, $this->w->owner, DocumentAction::Cancel)->blockers)->pluck('code'))->toContain('already_paid')
        ->and(collect($policy->availability($claim, $this->w->financeB, DocumentAction::Reverse)->blockers)->pluck('code'))->toContain('owner_only')
        ->and($this->p->balance('cash_in_hand'))->toBe(-70000);

    $this->actingAs($this->w->owner)
        ->post(route('hrm.expense-claims.reverse-payment', $claim), ['reason_text' => 'Paid from the wrong box'])
        ->assertSessionHasNoErrors();

    $claim->refresh();

    expect($claim->isSettled())->toBeFalse()
        ->and($claim->state())->toBe('Approved, awaiting payment')
        ->and($claim->approval_journal_id)->not->toBeNull()
        ->and($this->p->balance('cash_in_hand'))->toBe(0)
        ->and($this->p->balance('employee_reimbursements_payable'))->toBe(-70000)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);

    // Paid again, the right way.
    $this->p->paidNow($claim);
    expect($this->p->balance('employee_reimbursements_payable'))->toBe(0);
});

it('refuses to reverse a payment matched on the bank statement', function () {
    $claim = $this->p->approvedClaim('700');
    $claim = app(SettleExpenseClaim::class)->payNow($claim, $this->w->bank, null, $this->w->owner);
    $journal = ExpenseClaimActionPolicy::settlementJournalOf($claim);
    $line = $journal->lines()->where('amount_minor', '<', 0)->firstOrFail();

    $this->p->matchLine($line->id, '2026-10-17', '700');

    $availability = app(ExpenseClaimActionPolicy::class)->availability($claim, $this->w->owner, DocumentAction::Reverse);
    expect(collect($availability->blockers)->pluck('code'))->toContain('bank_matched');
});

it('with salary: the open month drops the line, and the claim can be put on salary again', function () {
    $period = $this->p->period(2026, 11);
    $claim = $this->p->onSalary($this->p->approvedClaim('400'), $period);
    $line = PayrollAdjustment::findOrFail($claim->payroll_adjustment_id);

    expect(PayrollAdjustment::payable()->count())->toBe(1);

    app(ReverseExpenseClaimPayment::class)->handle($claim, $this->w->owner, $this->p->reason('Wrong month chosen'));

    expect($line->fresh()->isCancelled())->toBeTrue()
        ->and($line->fresh()->source_key)->toContain('|cancelled:')
        ->and(PayrollAdjustment::payable()->count())->toBe(0)
        ->and($claim->fresh()->isSettled())->toBeFalse();

    $again = $this->p->onSalary($claim, $period);
    expect($again->isSettled())->toBeTrue()
        ->and(PayrollAdjustment::payable()->count())->toBe(1);
});

it('with salary: a month already closed for inputs cannot take the line back', function () {
    $period = $this->p->period(2026, 11);
    $claim = $this->p->onSalary($this->p->approvedClaim('400'), $period);

    PayrollRun::create(['business_id' => $this->w->business->id, 'payroll_period_id' => $period->id, 'version' => 1, 'status' => PayrollStatus::Approved]);

    $availability = app(ExpenseClaimActionPolicy::class)->availability($claim->fresh(), $this->w->owner, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code'))->toContain('salary_month_closed');
});

it('is registered, shows the right button per stage, and keeps the ledger whole', function () {
    expect(app(DocumentPolicyRegistry::class)->has(ExpenseClaimActionPolicy::TYPE))->toBeTrue();

    $approved = $this->p->approvedClaim();
    $paid = $this->p->paidNow($this->p->approvedClaim('300'));
    $policy = app(ExpenseClaimActionPolicy::class);

    expect(collect($policy->actionsFor($approved, $this->w->owner)->actions)->map(fn ($a) => $a->action->value)->all())->toBe(['cancel'])
        ->and(collect($policy->actionsFor($paid, $this->w->owner)->actions)->map(fn ($a) => $a->action->value)->all())->toBe(['reverse'])
        ->and($approved->approvalRequest->status)->toBe(ApprovalStatus::Approved)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);
});
