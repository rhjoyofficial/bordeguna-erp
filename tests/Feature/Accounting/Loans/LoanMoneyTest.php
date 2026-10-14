<?php

use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Actions\Loans\ReverseLoanEntry;
use App\Modules\Accounting\Enums\LoanStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanTdsRule;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Carbon;
use Tests\Support\LoanWorld;

/**
 * Money through the loan page (loans plan v2, phase 2; the ChatGPT release
 * gate): disbursement and receipt, repayment by the loan's waterfall both
 * ways, partial and early settlement, idempotent submission, the period lock,
 * reversal unlinking, and TDS on GROSS interest with the rule on and off.
 *
 * The default loan: 120,000 lent to Abdur Rahim at 12% from 1 Jan 2026,
 * twelve monthly equal installments. Installment 1 (1 Feb) is 10,661.85:
 * interest 1,223.01 (31 days) and principal 9,438.84.
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
    $this->head = fn (string $slug) => Account::bySlug($slug)->id;
    $this->line = fn (LoanEntry $entry, string $slug) => (int) JournalLine::query()->where('journal_id', $entry->journal_id)->where('account_id', ($this->head)($slug))->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('disburses a loan given: Dr the principal head for the counterparty and the loan, Cr the bank', function () {
    $loan = $this->w->approved();

    expect(LoanWorld::refusal(fn () => $this->w->advance($loan, '120000', '2025-12-31')))->toContain('starts on 1 Jan 2026')
        ->and(LoanWorld::refusal(fn () => $this->w->advance($loan, '120000.01', '2026-01-01')))->toContain('more than the approved principal');

    $entry = $this->w->advance($loan, '120000', '2026-01-01');
    $lines = JournalLine::query()->where('journal_id', $entry->journal_id)->get();
    $principal = $lines->firstWhere('account_id', ($this->head)('loans_to_other_parties'));

    expect($loan->fresh()->status)->toBe(LoanStatus::Active)
        ->and($entry->journal->source_id)->toBe($entry->id)
        ->and($principal->amount_minor)->toBe(12_000_000)
        ->and($principal->party_id)->toBe($this->w->rahim->id)
        ->and($principal->loan_contract_id)->toBe($loan->id)
        ->and($lines->firstWhere('money_account_id', $this->w->bank->id)->amount_minor)->toBe(-12_000_000);

    // Never more than the approved principal in total.
    expect(LoanWorld::refusal(fn () => $this->w->advance($loan, '1', '2026-01-02')))->toContain('0.00 is left to disburse');
});

it('receives a loan taken: Dr the bank, Cr the principal head', function () {
    $loan = $this->w->approved(['direction' => 'taken', 'counterparty_id' => $this->w->padma->id, 'maturity_date' => '2029-01-01']);
    $entry = $this->w->advance($loan, '120000', '2026-01-01');

    expect(($this->line)($entry, 'bank_loans'))->toBe(-12_000_000)
        ->and($this->w->ledger('bank_loans', $this->w->padma))->toBe(-12_000_000)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Active);
});

it('refuses money on a loan the Owner has not approved', function () {
    $draft = $this->w->draft();

    expect(LoanWorld::refusal(fn () => $this->w->advance($draft, '1000', '2026-01-01')))->toContain('Money moves only on an approved loan');
});

it('splits a repayment interest first by default: interest to 4920, principal to the loan head, installment paid', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');

    $entry = $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '10661.85']);
    $first = LoanPosition::of($loan->fresh())->currentRows()[0];

    expect($entry->interest_settled_minor)->toBe(122_301)
        ->and($entry->principal_minor)->toBe(-943_884)
        ->and(($this->line)($entry, 'interest_income'))->toBe(-122_301)
        ->and(($this->line)($entry, 'loans_to_other_parties'))->toBe(-943_884)
        ->and($entry->cash_minor)->toBe(1_066_185)
        ->and($first['status'])->toBe('paid')
        ->and($this->w->ledger('loans_to_other_parties', $this->w->rahim))->toBe(12_000_000 - 943_884);
});

it('follows a principal-first waterfall when the loan says so', function () {
    $interestFirst = $this->w->approved();
    $principalFirst = $this->w->approved(['waterfall' => 'principal_first']);
    $this->w->advance($interestFirst, '120000', '2026-01-01');
    $this->w->advance($principalFirst, '120000', '2026-01-01');

    $a = $this->w->repay($interestFirst, ['date' => '2026-02-01', 'amount' => '5000']);
    $b = $this->w->repay($principalFirst, ['date' => '2026-02-01', 'amount' => '5000']);

    expect([$a->interest_settled_minor, -$a->principal_minor])->toBe([122_301, 377_699])
        ->and([$b->interest_settled_minor, -$b->principal_minor])->toBe([0, 500_000]);
});

it('records a partial payment against the installment and leaves the rest overdue', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');
    $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '2000']);

    $first = LoanPosition::of($loan->fresh())->currentRows()[0];

    expect($first['interest_unpaid'])->toBe(0)
        ->and($first['principal_paid'])->toBe(200_000 - 122_301)
        ->and($first['principal_unpaid'])->toBe(943_884 - (200_000 - 122_301))
        ->and($first['status'])->toBe('overdue')
        ->and($loan->fresh()->status)->toBe(LoanStatus::Active);
});

it('settles the whole loan early, broken-period interest included, and closes the schedule', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');
    $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '10661.85']);

    // Outstanding 110,561.16 + installment 2 interest 1,017.75 + 14 days to 15 March 508.88.
    $entry = $this->w->repay($loan, ['date' => '2026-03-15', 'amount' => '112087.79']);
    $loan->refresh();
    $position = LoanPosition::of($loan);

    expect(-$entry->principal_minor)->toBe(11_056_116)
        ->and($entry->interest_settled_minor)->toBe(101_775 + 50_888)
        ->and($position->principal)->toBe(0)
        ->and($loan->status)->toBe(LoanStatus::Settled)
        ->and($loan->settled_on->toDateString())->toBe('2026-03-15')
        ->and(collect($position->currentRows())->pluck('status')->unique()->values()->all())->toBe(['paid', 'closed'])
        ->and($this->w->ledger('loans_to_other_parties', $this->w->rahim))->toBe(0);

    // Nothing more can be repaid on a settled loan.
    expect(LoanWorld::refusal(fn () => $this->w->repay($loan, ['date' => '2026-03-16', 'amount' => '1'])))->toContain('nothing to repay');
});

it('refuses more principal than is owed', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '60000', '2026-01-01');

    expect(LoanWorld::refusal(fn () => $this->w->repay($loan, ['date' => '2026-02-01', 'principal' => '60000.01', 'interest' => '0'])))
        ->toContain('more principal than is owed');
});

it('books a form sent twice only once', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '50000', '2026-01-01', 'form-1');

    expect(LoanWorld::refusal(fn () => $this->w->advance($loan, '50000', '2026-01-01', 'form-1')))->toContain('already recorded')
        ->and(LoanEntry::query()->where('loan_contract_id', $loan->id)->count())->toBe(1);

    $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '1000', 'submission_key' => 'repay-1']);
    expect(LoanWorld::refusal(fn () => $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '1000', 'submission_key' => 'repay-1'])))->toContain('already recorded')
        ->and(LoanEntry::query()->where('loan_contract_id', $loan->id)->count())->toBe(2);
});

it('respects the period lock and writes nothing when it refuses', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');
    AccountingPeriod::closeThrough(Carbon::parse('2026-02-28'), $this->w->owner);

    expect(LoanWorld::refusal(fn () => $this->w->repay($loan, ['date' => '2026-02-15', 'amount' => '1000'])))->toContain('closed')
        ->and(LoanEntry::query()->where('loan_contract_id', $loan->id)->count())->toBe(1);
});

it('reverses a repayment through the reversal path, unlinking it and opening its installment again', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');
    $entry = $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '10661.85']);

    expect(LoanWorld::refusal(fn () => app(ReverseLoanEntry::class)->handle($entry, $this->w->finance, ' ')))->toContain('Say why');

    $reversed = app(ReverseLoanEntry::class)->handle($entry, $this->w->finance, 'Bank returned the transfer');
    $first = LoanPosition::of($loan->fresh())->currentRows()[0];

    expect($reversed->isLive())->toBeFalse()
        ->and($reversed->reversalJournal->reverses_journal_id)->toBe($entry->journal_id)
        ->and($reversed->reversalJournal->journal_date->toDateString())->toBe('2026-02-01')
        ->and($first['principal_paid'] + $first['interest_paid'])->toBe(0)
        ->and(LoanPosition::of($loan->fresh())->principal)->toBe(12_000_000)
        ->and($this->w->ledger('loans_to_other_parties', $this->w->rahim))->toBe(12_000_000)
        ->and(LoanWorld::refusal(fn () => app(ReverseLoanEntry::class)->handle($reversed, $this->w->finance, 'again')))->toContain('already reversed');

    // The journal screen will not reverse it behind the register's back.
    $this->actingAs($this->w->owner)->post(route('accounting.journals.reverse', $entry->journal_id))->assertSessionHasErrors('lines');
});

it('brings a settled loan back to active when its last repayment is reversed', function () {
    $loan = $this->w->approved(['repayment_type' => 'bullet', 'frequency' => 'at_maturity', 'annual_rate' => '0', 'interest_free_reason' => 'Family help']);
    $this->w->advance($loan, '120000', '2026-01-01');
    $entry = $this->w->repay($loan, ['date' => '2026-05-01', 'amount' => '120000']);

    expect($loan->fresh()->status)->toBe(LoanStatus::Settled);

    app(ReverseLoanEntry::class)->handle($entry, $this->w->finance, 'Cheque bounced');
    expect($loan->fresh()->status)->toBe(LoanStatus::Active)->and($loan->fresh()->settled_on)->toBeNull();
});

it('settles accrued interest against 11753 first and only the rest in 4920', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');
    app(AccrueLoanInterest::class)->handle($loan->fresh(), '2026-01', $this->w->finance);

    $entry = $this->w->repay($loan, ['date' => '2026-02-01', 'principal' => '9438.84', 'interest' => '1500']);

    // January accrued 1,223.01; the 1,500 interest settles that, and 276.99 is February's, straight to income.
    expect(($this->line)($entry, 'interest_receivable'))->toBe(-122_301)
        ->and(($this->line)($entry, 'interest_income'))->toBe(-(150_000 - 122_301))
        ->and($this->w->ledger('interest_receivable', $this->w->rahim))->toBe(0)
        ->and(LoanPosition::of($loan->fresh())->accrued)->toBe(0);
});

it('pays a loan taken in full when TDS is off: Dr 6478 and the loan head, Cr the bank', function () {
    $loan = $this->w->approved(['direction' => 'taken']);
    $this->w->advance($loan, '120000', '2026-01-01');
    $entry = $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '10661.85']);

    expect(LoanTdsRule::query()->sole()->is_enabled)->toBeFalse()
        ->and(($this->line)($entry, 'interest_expense'))->toBe(122_301)
        ->and(($this->line)($entry, 'short_term_loans_payable'))->toBe(943_884)
        ->and(($this->line)($entry, 'tds_payable'))->toBe(0)
        ->and($entry->cash_minor)->toBe(1_066_185);
});

it('withholds TDS on the GROSS interest when the rule is on: Cr 2180, the lender paid net', function () {
    LoanTdsRule::query()->update(['is_enabled' => true, 'confirmed_by' => 'The accountant', 'confirmed_on' => '2026-01-01']);
    $loan = $this->w->approved(['direction' => 'taken']);
    $this->w->advance($loan, '120000', '2026-01-01');

    $entry = $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '10661.85']);

    // 10% of 1,223.01 gross interest = 122.30; the expense stays gross.
    expect($entry->tds_minor)->toBe(12_230)
        ->and((string) $entry->tds_rate)->toBe('10.0000')
        ->and(($this->line)($entry, 'tds_payable'))->toBe(-12_230)
        ->and(($this->line)($entry, 'interest_expense'))->toBe(122_301)
        ->and(($this->line)($entry, 'short_term_loans_payable'))->toBe(943_884)
        ->and($entry->cash_minor)->toBe(1_066_185 - 12_230);

    // A lender's certificate for a reduced rate replaces the rule's rate.
    $certified = $this->w->approved(['direction' => 'taken', 'tds_certificate_rate' => '5', 'tds_certificate_ref' => 'NBR/2026/123']);
    $this->w->advance($certified, '120000', '2026-01-01');
    expect($this->w->repay($certified, ['date' => '2026-02-01', 'amount' => '10661.85'])->tds_minor)->toBe(6_115);

    // A finance company is not a payee the rule names: nothing withheld.
    $nbfi = $this->w->approved(['direction' => 'taken', 'counterparty_id' => $this->w->padma->id]);
    $this->w->advance($nbfi, '120000', '2026-01-01');
    expect($this->w->repay($nbfi, ['date' => '2026-02-01', 'amount' => '10661.85'])->tds_minor)->toBe(0);
});

it('books TDS a borrower withheld from interest we receive as Advance Income Tax (1190)', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');

    $entry = $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '10661.85', 'tds_withheld' => '122.30']);

    expect(($this->line)($entry, 'advance_income_tax'))->toBe(12_230)
        ->and(($this->line)($entry, 'interest_income'))->toBe(-122_301)
        ->and($entry->cash_minor)->toBe(1_066_185 - 12_230)
        ->and(LoanWorld::refusal(fn () => $this->w->repay($loan, ['date' => '2026-03-01', 'amount' => '1000', 'tds_withheld' => '2000'])))->toContain('comes out of the interest');
});

it('records money from the loan page over HTTP and shows it on the page', function () {
    $loan = $this->w->approved();

    $this->actingAs($this->w->finance)->post(route('accounting.loans.advance', $loan), [
        'date' => '2026-01-01', 'money_account_id' => $this->w->bank->id, 'amount' => '120000', 'submission_key' => 'k1',
    ])->assertRedirect()->assertSessionHas('success');

    $this->actingAs($this->w->finance)->post(route('accounting.loans.repay', $loan), [
        'date' => '2026-02-01', 'money_account_id' => $this->w->bank->id, 'amount' => '10661.85', 'submission_key' => 'k2',
    ])->assertSessionHas('success');

    $entry = LoanEntry::query()->where('kind', 'repayment')->sole();
    $this->actingAs($this->w->finance)->post(route('accounting.loans.entries.reverse', $entry), ['reason' => 'Typed on the wrong loan'])->assertSessionHas('success');

    expect($entry->fresh()->isLive())->toBeFalse();
});
