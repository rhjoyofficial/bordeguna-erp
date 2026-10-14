<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\SaveCounterparty;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Counterparty;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\CounterpartyBalances;
use App\Modules\Accounting\Support\CounterpartyStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A counterparty's ledgers (loans plan v2, phase 1): the statement, the
 * balances, and the reconciliation that proves the counterparties add up to
 * each loan head, business by business.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-14 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna', 'fiscal_year_start_month' => 7]);
    $this->laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'laladia', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach([$this->business->id, $this->laladia->id]);

    $save = fn (array $data) => app(SaveCounterparty::class)->handle($data, $this->owner);
    $this->brac = $save(['legal_name' => 'BRAC Bank PLC', 'kind' => 'bank']);
    $this->tycoon = $save(['legal_name' => 'Tycoon - Hi-Tech Park', 'kind' => 'company', 'related_party' => true, 'relationship' => "The owner's company"]);

    $this->prepaid = Account::where('code', '1180')->sole()->id;
    $this->post = fn (string $date, string $slug, Counterparty $party, string $amount, bool $debit, string $memo = '') => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse($date),
        lines: $debit
            ? [JournalLineDraft::debit(Account::bySlug($slug)->id, Money::ofTaka($amount), $memo ?: null, $party), JournalLineDraft::credit($this->prepaid, Money::ofTaka($amount))]
            : [JournalLineDraft::credit(Account::bySlug($slug)->id, Money::ofTaka($amount), $memo ?: null, $party), JournalLineDraft::debit($this->prepaid, Money::ofTaka($amount))],
        memo: $memo ?: null,
    ), $this->owner);

    // The owner's case: 300,000 lent to his own company on 25 Aug, back on 27 Aug.
    ($this->post)('2026-08-25', 'loans_to_related_parties', $this->tycoon, '300000', true, 'Lent to Tycoon');
    ($this->post)('2026-08-27', 'loans_to_related_parties', $this->tycoon, '300000', false, 'Repaid by Tycoon');
    // A bank loan: 500,000 drawn, 50,000 repaid, interest owed.
    ($this->post)('2026-09-01', 'bank_loans', $this->brac, '500000', false, 'Term loan drawn');
    ($this->post)('2026-10-05', 'bank_loans', $this->brac, '50000', true, 'Instalment');
    ($this->post)('2026-10-05', 'interest_payable', $this->brac, '4200', false, 'September interest');

    // LaLaDia's own loan from the same bank: its books, not these.
    TenantScope::enter($this->laladia->id);
    ($this->post)('2026-09-10', 'bank_loans', $this->brac, '90000', false, 'LaLaDia loan');
    TenantScope::enter($this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

it('balances each counterparty per loan head, in this business only', function () {
    expect(CounterpartyBalances::of($this->brac))->toBe([
        'interest_payable' => -420_000,
        'bank_loans' => -45_000_000,
    ])
        // Lent and repaid: nothing left, so nothing listed.
        ->and(CounterpartyBalances::of($this->tycoon))->toBe([])
        // As at a date before the repayment.
        ->and(CounterpartyBalances::of($this->tycoon, Carbon::parse('2026-08-26')))->toBe(['loans_to_related_parties' => 30_000_000])
        // Across the group, for the guards that need it.
        ->and(CounterpartyBalances::of($this->brac, everyBusiness: true)['bank_loans'])->toBe(-54_000_000);

    TenantScope::enter($this->laladia->id);
    expect(CounterpartyBalances::of($this->brac))->toBe(['bank_loans' => -9_000_000]);
});

it('tells one counterparty\'s story per head, with opening and closing balances', function () {
    $statement = CounterpartyStatement::for($this->brac, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());

    $heads = $statement->heads->keyBy(fn (array $head) => $head['account']->system_slug);

    expect($heads->keys()->all())->toBe(['interest_payable', 'bank_loans'])
        ->and($heads['bank_loans']['opening']->minor)->toBe(-50_000_000)
        ->and($heads['bank_loans']['debits']->minor)->toBe(5_000_000)
        ->and($heads['bank_loans']['credits']->minor)->toBe(0)
        ->and($heads['bank_loans']['closing']->minor)->toBe(-45_000_000)
        ->and($heads['bank_loans']['lines'])->toHaveCount(1)
        ->and($heads['bank_loans']['lines'][0]['memo'])->toBe('Instalment')
        ->and($heads['interest_payable']['opening']->minor)->toBe(0)
        ->and($heads['interest_payable']['closing']->minor)->toBe(-420_000);
});

it('shows a head with no movement in the period only when it carries an opening balance', function () {
    $statement = CounterpartyStatement::for($this->tycoon, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')->endOfDay());
    expect($statement->heads)->toHaveCount(0);

    $statement = CounterpartyStatement::for($this->tycoon, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')->endOfDay());
    expect($statement->heads)->toHaveCount(1)
        ->and($statement->heads[0]['lines'])->toHaveCount(2)
        ->and($statement->heads[0]['closing']->minor)->toBe(0);
});

it('adds every counterparty\'s statement up to the loan head', function () {
    $from = Carbon::parse('2026-07-01');
    $to = Carbon::parse('2026-10-31')->endOfDay();

    foreach (CounterpartyBalances::heads() as $slug => $account) {
        $parties = Counterparty::all()->sum(function (Counterparty $party) use ($from, $to, $slug) {
            $head = CounterpartyStatement::for($party, $from, $to)->heads->first(fn (array $h) => $h['account']->system_slug === $slug);

            return $head === null ? 0 : $head['closing']->minor;
        });

        expect($parties)->toBe((int) JournalLine::query()->where('account_id', $account->id)->sum('amount_minor'), $slug);
    }
});

it('finds the counterparty ledgers agreeing with their heads', function () {
    $result = SubledgerReconciliation::reconcileCurrentBusiness();

    expect($result['problems'])->toBe([])
        ->and($result['warnings'])->toBe([]);
});

it('reports a loan head whose counterparties do not add up to it', function () {
    $journal = DB::table('journals')->where('business_id', $this->business->id)->value('id');
    $bankLoans = Account::bySlug('bank_loans')->id;

    // Slipped in around PostJournal: one line naming nobody, one naming a
    // counterparty that does not exist.
    DB::table('journal_lines')->insert([
        ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 8, 'account_id' => $bankLoans, 'amount_minor' => -10_000, 'party_type' => null, 'party_id' => null, 'created_at' => now()],
        ['journal_id' => $journal, 'business_id' => $this->business->id, 'line_no' => 9, 'account_id' => $bankLoans, 'amount_minor' => -5_000, 'party_type' => Counterparty::class, 'party_id' => 999, 'created_at' => now()],
    ]);

    $problems = collect(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->filter(fn ($p) => str_contains($p, '2210'))->values();

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('Bor de Guna: 2210 - Bank Loans is 450,150.00 Cr in the ledger but its counterparties add up to 450,000.00 Cr')
        ->and($problems[0])->toContain('1 posting(s) name no counterparty')
        ->and($problems[0])->toContain('counterparty id 999 does not exist');
});

it('warns when a balance sits on a head its counterparty no longer fits', function () {
    ($this->post)('2026-10-10', 'loans_to_related_parties', $this->tycoon, '20000', true);
    DB::table('counterparties')->where('id', $this->tycoon->id)->update(['related_party' => false]);

    $warnings = SubledgerReconciliation::reconcileCurrentBusiness()['warnings'];

    expect($warnings)->toContain('Bor de Guna: Tycoon - Hi-Tech Park holds 20,000.00 on 11752 - Loans to Related Parties but is no longer marked a related party. Move it to 11751 by a journal voucher, or mark it related again.');
});

it('refuses deactivating a counterparty that still has a balance in any business, and allows it once settled', function () {
    $refused = fn (Counterparty $party) => rescue(fn () => app(SaveCounterparty::class)->deactivate($party, $this->owner) && null, fn (ValidationException $e) => collect($e->errors())->flatten()->first(), false);

    expect($refused($this->brac))->toContain('BRAC Bank PLC still has balances: 2127 - Interest Payable 4,200.00 Cr (Bor de Guna); 2210 - Bank Loans 450,000.00 Cr (Bor de Guna); 2210 - Bank Loans 90,000.00 Cr (LaLaDia)');

    app(SaveCounterparty::class)->deactivate($this->tycoon, $this->owner);
    expect($this->tycoon->fresh()->is_active)->toBeFalse();

    app(SaveCounterparty::class)->activate($this->tycoon, $this->owner);
    expect($this->tycoon->fresh()->is_active)->toBeTrue();
});
