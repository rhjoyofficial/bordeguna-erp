<?php

use App\Modules\Accounting\Actions\Loans\AccrueLoanInterest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\LoanTdsRule;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanReclassification;
use App\Modules\Accounting\Support\Loans\LoanReconciliation;
use App\Modules\Accounting\Support\Loans\LoanReports;
use Illuminate\Support\Carbon;
use Tests\Support\LoanWorld;

/**
 * The register against the ledger, and what the register reports (loans plan
 * v2, phase 2; ChatGPT release gate: schedule-to-GL reconciliation,
 * current/non-current reclassification).
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
    $this->accrue = fn ($loan, string $period) => app(AccrueLoanInterest::class)->handle($loan->fresh(), $period, $this->w->finance);
});

afterEach(fn () => Carbon::setTestNow());

it('reconciles the register to the ledger loan by loan and head by head after every kind of movement', function () {
    $given = $this->w->approved();
    $taken = $this->w->approved(['direction' => 'taken', 'counterparty_id' => $this->w->padma->id, 'maturity_date' => '2029-01-01']);
    $longGiven = $this->w->approved(['counterparty_id' => $this->w->tycoon->id, 'maturity_date' => '2028-01-01', 'principal' => '240000']);

    foreach ([$given, $taken, $longGiven] as $loan) {
        $this->w->advance($loan, $loan === $longGiven ? '240000' : '120000', '2026-01-01');
        ($this->accrue)($loan, '2026-01');
        $this->w->repay($loan, ['date' => '2026-02-01', 'amount' => '5000']);
    }

    $result = LoanReconciliation::build();

    expect($result['agrees'])->toBeTrue()
        ->and(collect($result['loans'])->every(fn ($row) => $row['agrees']))->toBeTrue()
        ->and(collect($result['heads'])->pluck('account.code')->all())->toContain('11751', '11753', '1270', '2127', '2210');

    $row = collect($result['loans'])->firstWhere(fn ($r) => $r['loan']->id === $given->id);
    expect($row['register_principal'])->toBe(LoanPosition::of($given->fresh())->principal)
        ->and($row['ledger_principal'])->toBe($this->w->ledger('loans_to_other_parties', $this->w->rahim));
});

it('shows money booked for a loan counterparty that names no loan as a difference on its head', function () {
    // The Tycoon one-off: lent by hand before the register existed.
    $this->actingAs($this->w->owner)->post('/accounting/vouchers/payment', [
        'voucher_date' => '2026-01-01', 'money_account_id' => $this->w->bank->id,
        'lines' => [['account_id' => Account::bySlug('loans_to_related_parties')->id, 'amount' => '5000', 'party_id' => $this->w->tycoon->id]],
        'post' => true,
    ])->assertSessionHasNoErrors();

    $loan = $this->w->approved(['counterparty_id' => $this->w->tycoon->id]);
    $this->w->advance($loan, '120000', '2026-01-02');

    $result = LoanReconciliation::build();
    $head = collect($result['heads'])->firstWhere(fn ($h) => $h['account']->code === '11752');

    expect($result['agrees'])->toBeFalse()
        ->and($head['register'])->toBe(12_000_000)
        ->and($head['ledger'])->toBe(12_500_000)
        ->and($head['untagged'])->toBe(500_000)
        ->and($head['difference'])->toBe(-500_000)
        // The loan itself agrees: the difference is the untagged money, not the loan.
        ->and(collect($result['loans'])->every(fn ($row) => $row['agrees']))->toBeTrue();
});

it('proposes the current/non-current reclassification from the schedule, posts it by hand, and proposes nothing twice', function () {
    $given = $this->w->approved(['principal' => '240000', 'maturity_date' => '2028-01-01']);
    $taken = $this->w->approved(['direction' => 'taken', 'counterparty_id' => $this->w->padma->id, 'maturity_date' => '2029-01-01']);
    $this->w->advance($given, '240000', '2026-01-01');
    $this->w->advance($taken, '120000', '2026-01-01');

    $asAt = Carbon::parse('2026-06-30');
    $due = fn ($loan) => LoanPosition::of($loan->fresh(), $asAt)->unpaidPrincipalDueBy(Carbon::parse('2027-06-30'));
    $lines = LoanReclassification::journalLines($asAt);
    $byAccount = collect($lines)->groupBy('account_id');

    $code = fn (string $slug) => Account::bySlug($slug)->id;

    expect($lines)->toHaveCount(4)
        // Given: Dr 11754 / Cr 1270, the counterparty and the loan on each line.
        ->and($byAccount[$code('current_portion_of_loans_given')][0]['debit'])->toBe(App\Modules\Accounting\Support\Money::ofMinor($due($given))->toTaka())
        ->and($byAccount[$code('long_term_loans_given')][0]['credit'])->toBe(App\Modules\Accounting\Support\Money::ofMinor($due($given))->toTaka())
        ->and($byAccount[$code('long_term_loans_given')][0]['loan_contract_id'])->toBe($given->id)
        // Taken: Dr 2210 / Cr 2128.
        ->and($byAccount[$code('bank_loans')][0]['debit'])->toBe(App\Modules\Accounting\Support\Money::ofMinor($due($taken))->toTaka())
        ->and($byAccount[$code('current_portion_of_long_term_loans')][0]['credit'])->toBe(App\Modules\Accounting\Support\Money::ofMinor($due($taken))->toTaka())
        ->and($byAccount[$code('current_portion_of_long_term_loans')][0]['party_id'])->toBe($this->w->padma->id);

    // The helper only prepares: the journal screen opens with the lines filled in.
    $this->actingAs($this->w->owner)->get('/accounting/journals/new/manual?reclass_as_at=2026-06-30')
        ->assertInertia(fn ($page) => $page->has('prefill.lines', 4)->where('prefill.date', '2026-06-30'));

    // The Owner posts it there.
    $this->actingAs($this->w->owner)->post('/accounting/journals', [
        'journal_date' => '2026-06-30', 'journal_type' => 'manual', 'memo' => 'Current portion at 30 Jun 2026',
        'lines' => array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit' => $l['debit'] ?: null, 'credit' => $l['credit'] ?: null, 'party_id' => $l['party_id'], 'loan_contract_id' => $l['loan_contract_id']], $lines),
    ])->assertSessionHasNoErrors();

    expect(LoanReclassification::journalLines($asAt))->toBe([])
        ->and($this->w->ledger('current_portion_of_loans_given', $this->w->rahim))->toBe($due($given))
        ->and(LoanReconciliation::build()['agrees'])->toBeTrue()
        // Principal is unchanged by moving it between its two heads.
        ->and(LoanPosition::of($given->fresh())->principal)->toBe(24_000_000);

    // A repayment then takes principal from the current portion first.
    $entry = $this->w->repay($given, ['date' => '2026-07-01', 'principal' => '1000', 'interest' => '0']);
    expect((int) App\Modules\Accounting\Models\JournalLine::query()->where('journal_id', $entry->journal_id)->where('account_id', $code('current_portion_of_loans_given'))->sum('amount_minor'))->toBe(-100_000);
});

it('buckets principal by maturity so the buckets add up to what is owed, and lists what is overdue', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');

    $row = LoanReports::maturity(Carbon::parse('2026-10-14'))[0];

    expect($row['overdue'] + $row['within_12_months'] + $row['later'] + $row['unscheduled'])->toBe($row['outstanding'])
        ->and($row['outstanding'])->toBe(12_000_000)
        ->and($row['later'])->toBe(0)
        ->and($row['overdue'])->toBeGreaterThan(0);

    $overdue = LoanReports::overdue(Carbon::parse('2026-10-14'));
    expect($overdue)->toHaveCount(9)
        ->and($overdue[0]['due_date']->toDateString())->toBe('2026-02-01')
        ->and($overdue[0]['days_late'])->toBe(255);
});

it('lists related-party loans, interest by period, and TDS on interest', function () {
    LoanTdsRule::query()->update(['is_enabled' => true, 'confirmed_by' => 'The accountant', 'confirmed_on' => '2026-01-01']);
    $related = $this->w->approved(['counterparty_id' => $this->w->tycoon->id, 'annual_rate' => '0', 'interest_free_reason' => "Owner's company, agreed interest-free"]);
    $given = $this->w->approved();
    $taken = $this->w->approved(['direction' => 'taken']);
    $this->w->advance($related, '50000', '2026-01-01');
    $this->w->advance($given, '120000', '2026-01-01');
    $this->w->advance($taken, '120000', '2026-01-01');
    ($this->accrue)($given, '2026-01');
    $this->w->repay($given, ['date' => '2026-02-01', 'amount' => '10661.85']);
    $this->w->repay($taken, ['date' => '2026-02-01', 'amount' => '10661.85']);

    expect(collect(LoanReports::related(Carbon::parse('2026-10-14')))->pluck('loan.id')->all())->toBe([$related->id]);

    $interest = collect(LoanReports::interest(Carbon::parse('2026-01-01'), Carbon::parse('2026-02-28')))->keyBy('loan.id');
    expect($interest[$given->id]['accrued'])->toBe(122_301)
        ->and($interest[$given->id]['direct'])->toBe(0)
        ->and($interest[$given->id]['settled'])->toBe(122_301)
        ->and($interest[$taken->id]['direct'])->toBe(122_301)
        ->and($interest[$taken->id]['tds'])->toBe(12_230);

    $tds = LoanReports::tds(Carbon::parse('2026-01-01'), Carbon::parse('2026-02-28'));
    expect($tds)->toHaveCount(1)
        ->and($tds[0]['loan']->id)->toBe($taken->id)
        ->and($tds[0]['gross'])->toBe(122_301)
        ->and($tds[0]['tds'])->toBe(12_230);
});

it('opens every report, the loan page and the printable statement', function () {
    $loan = $this->w->approved();
    $this->w->advance($loan, '120000', '2026-01-01');

    foreach (['receivable', 'payable', 'maturity', 'overdue', 'related', 'interest', 'tds', 'reconciliation'] as $report) {
        $this->actingAs($this->w->manager)->get("/accounting/loans/reports?report={$report}")
            ->assertOk()->assertInertia(fn ($page) => $page->component('accounting/loans/reports')->where('report', $report));
    }

    $this->actingAs($this->w->finance)->get("/accounting/loans/{$loan->id}")
        ->assertInertia(fn ($page) => $page->component('accounting/loans/show')
            ->where('loan.number', 'LN-2026-0001')
            ->has('schedule', 12)
            ->where('position.agrees', true));

    $this->actingAs($this->w->finance)->get("/accounting/loans/{$loan->id}/statement")
        ->assertInertia(fn ($page) => $page->component('accounting/loans/statement')->has('heads', 1));

    $this->actingAs($this->w->finance)->get('/accounting/loans/accruals?period=2026-01')
        ->assertInertia(fn ($page) => $page->component('accounting/loans/accruals')->has('rows', 1));

    $this->actingAs($this->w->finance)->get('/accounting/loans/tds-rules')
        ->assertInertia(fn ($page) => $page->component('accounting/loans/tds-rules')->has('rules', 1)->where('rules.0.is_enabled', false));
});
