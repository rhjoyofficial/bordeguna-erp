<?php

use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Actions\Loans\LoanAmendments;
use App\Modules\Accounting\Actions\Loans\ReverseLoanEntry;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\Loans\LoanInterest;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Tests\Support\LoanWorld;

/**
 * Month-end interest accrual (loans plan v2, phase 2; ChatGPT confirmation,
 * point 8): actual/365 on the real daily balance, previewed, never posted
 * twice for a loan, month and terms version, and changed afterwards only by a
 * controlled adjustment, never by rewriting what was posted.
 *
 * The loan: 120,000 at 12%, lent on 15 Jan 2026. January earns 17 days
 * (670.68), February 28 days (1,104.66).
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
    $this->accrue = fn ($loan, string $period) => app(AccrueLoanInterest::class)->handle($loan->fresh(), $period, $this->w->finance);
    $this->given = function () {
        $loan = $this->w->approved(['start_date' => '2026-01-15', 'maturity_date' => '2027-01-15']);
        $this->w->advance($loan, '120000', '2026-01-15');

        return $loan->fresh();
    };
    $this->onHead = fn (LoanEntry $entry, string $slug) => (int) JournalLine::query()->where('journal_id', $entry->journal_id)->where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('accrues a loan given at actual/365 from the day the money left: Dr 11753, Cr 4920', function () {
    $loan = ($this->given)();

    $january = ($this->accrue)($loan, '2026-01');
    $february = ($this->accrue)($loan, '2026-02');

    // 120,000 x 12% x 17/365 = 670.68; x 28/365 = 1,104.66.
    expect($january->accrued_interest_minor)->toBe(67_068)
        ->and($january->entry_date->toDateString())->toBe('2026-01-31')
        ->and($january->kind)->toBe(LoanEntryKind::Accrual)
        ->and(($this->onHead)($january, 'interest_receivable'))->toBe(67_068)
        ->and(($this->onHead)($january, 'interest_income'))->toBe(-67_068)
        ->and($february->accrued_interest_minor)->toBe(110_466)
        ->and($this->w->ledger('interest_receivable', $this->w->rahim))->toBe(67_068 + 110_466);
});

it('accrues a loan taken the other way: Dr 6478, Cr 2127', function () {
    $loan = $this->w->approved(['direction' => 'taken', 'start_date' => '2026-01-15', 'maturity_date' => '2027-01-15']);
    $this->w->advance($loan, '120000', '2026-01-15');

    $entry = ($this->accrue)($loan, '2026-01');

    expect(($this->onHead)($entry, 'interest_expense'))->toBe(67_068)
        ->and(($this->onHead)($entry, 'interest_payable'))->toBe(-67_068);
});

it('never posts the same month twice: a rerun finds nothing, and the database refuses a duplicate key', function () {
    $loan = ($this->given)();
    $first = ($this->accrue)($loan, '2026-01');

    expect(($this->accrue)($loan, '2026-01'))->toBeNull()
        ->and(LoanEntry::query()->where('accrual_period', '2026-01')->count())->toBe(1)
        ->and($first->accrual_key)->toBe("{$loan->id}:2026-01:v1");

    // Two people pressing it at once: the second insert is refused by the index.
    expect(fn () => LoanEntry::query()->create([
        'loan_contract_id' => $loan->id, 'kind' => 'accrual', 'entry_date' => '2026-01-31', 'accrual_period' => '2026-01',
        'accrual_version' => 1, 'accrual_key' => "{$loan->id}:2026-01:v1",
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('posts a controlled adjustment, never a rewrite, when a backdated repayment changes a month already accrued', function () {
    $loan = ($this->given)();
    $original = ($this->accrue)($loan, '2026-02');
    $this->w->repay($loan, ['date' => '2026-02-10', 'principal' => '60000', 'interest' => '0']);

    $adjustment = ($this->accrue)($loan, '2026-02');

    // 9 days on 120,000 and 19 on 60,000 at 12%: 729.86, so 374.80 comes back off.
    expect($adjustment->kind)->toBe(LoanEntryKind::AccrualAdjustment)
        ->and($adjustment->accrued_interest_minor)->toBe(72_986 - 110_466)
        ->and($adjustment->accrual_key)->toBe("{$loan->id}:2026-02:v1:t72986")
        ->and(($this->onHead)($adjustment, 'interest_income'))->toBe(37_480)
        ->and(($this->onHead)($adjustment, 'interest_receivable'))->toBe(-37_480)
        ->and($original->fresh()->accrued_interest_minor)->toBe(110_466)
        ->and($original->fresh()->isLive())->toBeTrue()
        ->and(AccrueLoanInterest::accruedFor($loan, '2026-02'))->toBe(72_986)
        ->and(($this->accrue)($loan, '2026-02'))->toBeNull();
});

it('keeps the history and adjusts the accrual when an amendment changes the rate inside an accrued month', function () {
    $loan = ($this->given)();
    $original = ($this->accrue)($loan, '2026-02');

    $amendments = app(LoanAmendments::class);
    $amendment = $amendments->request($loan->fresh(), [
        'effective_from' => '2026-02-20', 'annual_rate' => '6', 'repayment_type' => 'equal_installments', 'frequency' => 'monthly',
        'maturity_date' => '2027-01-15', 'reason' => 'Rate cut agreed in writing on 18 Feb',
    ], $this->w->finance);
    $amendments->approve($amendment, $this->w->owner);

    $adjustment = ($this->accrue)($loan, '2026-02');

    // 19 days at 12% (749.59) and 9 days at 6% (177.53) = 927.12; 1,104.66 was accrued.
    expect($loan->fresh()->terms_version)->toBe(2)
        ->and($adjustment->accrual_version)->toBe(2)
        ->and($adjustment->accrual_key)->toBe("{$loan->id}:2026-02:v2")
        ->and($adjustment->accrued_interest_minor)->toBe(92_712 - 110_466)
        ->and($original->fresh()->accrued_interest_minor)->toBe(110_466)
        ->and($original->fresh()->isLive())->toBeTrue();
});

it('accrues a month again once its accrual is reversed', function () {
    $loan = ($this->given)();
    $entry = ($this->accrue)($loan, '2026-01');

    app(ReverseLoanEntry::class)->handle($entry, $this->w->finance, 'Run before the disbursement was corrected');

    $again = ($this->accrue)($loan, '2026-01');

    expect($again)->not->toBeNull()
        ->and($again->kind)->toBe(LoanEntryKind::Accrual)
        ->and($again->accrual_key)->toBe("{$loan->id}:2026-01:v1")
        ->and(AccrueLoanInterest::accruedFor($loan, '2026-01'))->toBe(67_068);
});

it('stops accruing once the principal is back', function () {
    $loan = ($this->given)();
    $this->w->repay($loan, ['date' => '2026-03-31', 'principal' => '120000', 'interest' => '0']);

    expect(LoanInterest::forPeriod($loan->fresh(), Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30')))->toBe(0)
        ->and(($this->accrue)($loan->fresh(), '2026-04'))->toBeNull()
        // March earns its 30 days and not the 31st.
        ->and(LoanInterest::forPeriod($loan->fresh(), Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')))->toBe((int) round(12_000_000 * 30 * 0.12 / 365));
});

it('refuses a month that has not ended, and posts a closed month on the first open day', function () {
    $loan = ($this->given)();

    expect(LoanWorld::refusal(fn () => ($this->accrue)($loan, '2026-10')))->toContain('has not ended yet');

    AccountingPeriod::closeThrough(Carbon::parse('2026-02-28'), $this->w->owner);
    $entry = ($this->accrue)($loan, '2026-02');

    expect($entry->entry_date->toDateString())->toBe('2026-03-01')
        ->and($entry->accrual_period)->toBe('2026-02');
});

it('previews every active interest-bearing loan before posting, and posts them all from the screen', function () {
    $loan = ($this->given)();
    $free = $this->w->approved(['annual_rate' => '0', 'interest_free_reason' => 'Family']);
    $this->w->advance($free, '1000', '2026-01-01');

    $preview = app(AccrueLoanInterest::class)->preview('2026-02');

    expect($preview)->toHaveCount(1)
        ->and($preview[0]['loan']->id)->toBe($loan->id)
        ->and($preview[0]['computed'])->toBe(110_466)
        ->and($preview[0]['accrued'])->toBe(0)
        ->and($preview[0]['difference'])->toBe(110_466);

    $this->actingAs($this->w->manager)->post(route('accounting.loans.accruals.store'), ['period' => '2026-02'])->assertForbidden();
    $this->actingAs($this->w->finance)->post(route('accounting.loans.accruals.store'), ['period' => '2026-02'])->assertSessionHas('success');
    $this->actingAs($this->w->finance)->post(route('accounting.loans.accruals.store'), ['period' => '2026-02'])->assertSessionHas('success');

    expect(LoanEntry::query()->where('accrual_period', '2026-02')->count())->toBe(1);
});
