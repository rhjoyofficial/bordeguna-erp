<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Money;
use Illuminate\Support\Carbon;
use Tests\Support\LoanWorld;

/**
 * ⚠⚠ The rule that keeps the register and the ledger one record (loans plan
 * v2, phase 2; ChatGPT release gate "unauthorised direct postings to loan
 * heads" and "counterparty isolation"):
 *
 *  - a manual voucher or journal on a loan head, for a counterparty with an
 *    ACTIVE register loan on that head, must NAME the loan;
 *  - a counterparty with no register loan is booked by hand as before;
 *  - a line naming a loan must fit it (counterparty, head, business, approved);
 *  - a voucher naming a loan moves the loan, and reversing it unlinks it.
 */
beforeEach(function () {
    $this->w = LoanWorld::make();
    $this->loan = $this->w->approved();
    $this->w->advance($this->loan, '120000', '2026-01-01');
    $this->head = fn (string $slug) => Account::bySlug($slug)->id;
    $this->receive = fn (array $line) => $this->actingAs($this->w->owner)->post('/accounting/vouchers/receipt', [
        'voucher_date' => '2026-02-01',
        'money_account_id' => $this->w->bank->id,
        'lines' => [$line],
        'post' => true,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('refuses a manual voucher on the loan head of a counterparty with an active loan that does not name the loan', function () {
    ($this->receive)(['account_id' => ($this->head)('loans_to_other_parties'), 'amount' => '10000', 'party_id' => $this->w->rahim->id])
        ->assertSessionHasErrors(['lines.0.loan_contract_id']);

    expect(session('errors')->first('lines.0.loan_contract_id'))->toContain('Abdur Rahim has an active register loan on 11751 - Loans to Other Parties (LN-2026-0001). Name the loan on this line')
        ->and(Voucher::sole()->status)->toBe(VoucherStatus::Draft)
        ->and(LoanEntry::query()->where('kind', 'manual')->count())->toBe(0);
});

it('posts a voucher that names the loan, and the loan moves with it: register entry, installments settled', function () {
    ($this->receive)([
        'account_id' => ($this->head)('loans_to_other_parties'), 'amount' => '10000',
        'party_id' => $this->w->rahim->id, 'loan_contract_id' => $this->loan->id,
    ])->assertSessionHasNoErrors();

    $voucher = Voucher::sole();
    $entry = LoanEntry::query()->where('kind', LoanEntryKind::Manual->value)->sole();
    $line = JournalLine::query()->where('journal_id', $voucher->journal_id)->where('account_id', ($this->head)('loans_to_other_parties'))->sole();
    $position = LoanPosition::of($this->loan->fresh());

    expect($line->loan_contract_id)->toBe($this->loan->id)
        ->and($entry->journal_id)->toBe($voucher->journal_id)
        ->and($entry->principal_minor)->toBe(-1_000_000)
        ->and($position->principal)->toBe(11_000_000)
        ->and($position->currentRows()[0]['principal_paid'])->toBe(943_884)
        ->and($position->currentRows()[1]['principal_paid'])->toBe(1_000_000 - 943_884);
});

it('books a counterparty with no register loan by hand, as before (the Tycoon one-off)', function () {
    $this->actingAs($this->w->owner)->post('/accounting/vouchers/payment', [
        'voucher_date' => '2026-02-01',
        'money_account_id' => $this->w->bank->id,
        'lines' => [['account_id' => ($this->head)('loans_to_related_parties'), 'amount' => '5000', 'party_id' => $this->w->tycoon->id]],
        'post' => true,
    ])->assertSessionHasNoErrors();

    expect(Voucher::sole()->status)->toBe(VoucherStatus::Posted)
        ->and($this->w->ledger('loans_to_related_parties', $this->w->tycoon))->toBe(500_000);
});

it('refuses a line that names a loan it does not fit: another counterparty, another head, a loan not yet approved', function () {
    ($this->receive)(['account_id' => ($this->head)('loans_to_related_parties'), 'amount' => '100', 'party_id' => $this->w->tycoon->id, 'loan_contract_id' => $this->loan->id])
        ->assertSessionHasErrors(['lines.0.loan_contract_id']);
    expect(session('errors')->first('lines.0.loan_contract_id'))->toContain('LN-2026-0001 is with Abdur Rahim, but this line names Tycoon - Hi-Tech Park');

    ($this->receive)(['account_id' => ($this->head)('long_term_loans_given'), 'amount' => '100', 'party_id' => $this->w->rahim->id, 'loan_contract_id' => $this->loan->id])
        ->assertSessionHasErrors(['lines.0.loan_contract_id']);
    expect(session('errors')->first('lines.0.loan_contract_id'))->toContain('is kept on 11751 - Loans to Other Parties, 11753 - Interest Receivable, not on 1270 - Long-term Loans Given');

    $draft = $this->w->draft(['counterparty_id' => $this->w->padma->id]);
    ($this->receive)(['account_id' => ($this->head)('loans_to_other_parties'), 'amount' => '100', 'party_id' => $this->w->padma->id, 'loan_contract_id' => $draft->id])
        ->assertSessionHasErrors(['lines.0.loan_contract_id']);
    expect(session('errors')->first('lines.0.loan_contract_id'))->toContain('A loan takes postings only once the Owner has approved it');
});

it('enforces the rule for every writer in PostJournal, not only on the screens', function () {
    $refusal = LoanWorld::refusal(fn () => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-02-01'),
        lines: [
            JournalLineDraft::credit(($this->head)('loans_to_other_parties'), Money::ofTaka('100'), null, $this->w->rahim),
            JournalLineDraft::debit(Account::where('code', '1180')->sole()->id, Money::ofTaka('100')),
        ],
    ), $this->w->owner));

    expect($refusal)->toContain('Line 1: Abdur Rahim has an active register loan');
});

it('keeps each business to its own loans: LaLaDia is never forced to name, and can never name, Bor de Guna\'s loan', function () {
    $this->w->enter($this->w->laladia);
    $lalaOwner = $this->w->member('Owner', $this->w->laladia);
    $post = fn (?int $loanId) => app(PostJournal::class)->handle(new JournalDraft(
        date: Carbon::parse('2026-02-01'),
        lines: [
            JournalLineDraft::debit(($this->head)('loans_to_other_parties'), Money::ofTaka('100'), null, $this->w->rahim, null, $loanId),
            JournalLineDraft::credit(Account::where('code', '1180')->sole()->id, Money::ofTaka('100')),
        ],
    ), $lalaOwner);

    expect(LoanWorld::refusal(fn () => $post($this->loan->id)))->toContain('LN-2026-0001 belongs to another business')
        ->and(LoanWorld::refusal(fn () => $post(null)))->toBe('');

    // And the loan is not even found from LaLaDia.
    expect(LoanContract::query()->find($this->loan->id))->toBeNull();
});

it('unlinks the loan movement when the voucher that named it is reversed', function () {
    ($this->receive)([
        'account_id' => ($this->head)('loans_to_other_parties'), 'amount' => '10000',
        'party_id' => $this->w->rahim->id, 'loan_contract_id' => $this->loan->id,
    ])->assertSessionHasNoErrors();

    $voucher = Voucher::sole();
    $this->actingAs($this->w->owner)->post("/accounting/vouchers/doc/{$voucher->id}/reverse", ['reason' => 'Posted to the wrong loan'])->assertSessionHasNoErrors();

    $entry = LoanEntry::query()->where('kind', 'manual')->sole();

    expect($entry->reversed_at)->not->toBeNull()
        ->and($entry->isLive())->toBeFalse()
        ->and(LoanPosition::of($this->loan->fresh())->principal)->toBe(12_000_000)
        ->and(LoanPosition::of($this->loan->fresh())->currentRows()[0]['principal_paid'])->toBe(0);

    // The reversing journal carried the loan's id too, so the ledger by loan nets to the advance.
    expect((int) JournalLine::query()->where('loan_contract_id', $this->loan->id)->sum('amount_minor'))->toBe(12_000_000);
});

it('takes a manual journal that names the loan, and its reversal from the journal screen', function () {
    $this->actingAs($this->w->owner)->post('/accounting/journals', [
        'journal_date' => '2026-02-01',
        'journal_type' => 'manual',
        'memo' => 'Bank deducted the installment',
        'lines' => [
            ['account_id' => Account::where('code', '1180')->sole()->id, 'debit' => '500'],
            ['account_id' => ($this->head)('loans_to_other_parties'), 'credit' => '500', 'party_id' => $this->w->rahim->id, 'loan_contract_id' => $this->loan->id],
        ],
    ])->assertSessionHasNoErrors();

    $journal = Journal::query()->where('memo', 'Bank deducted the installment')->sole();
    $entry = LoanEntry::query()->where('journal_id', $journal->id)->sole();

    expect($entry->principal_minor)->toBe(-50_000);

    $this->actingAs($this->w->owner)->post(route('accounting.journals.reverse', $journal))->assertSessionHasNoErrors();

    expect($entry->fresh()->isLive())->toBeFalse()
        ->and(LoanPosition::of($this->loan->fresh())->principal)->toBe(12_000_000);
});

it('offers the loans to the voucher and journal forms with the heads they sit on', function () {
    $this->actingAs($this->w->owner)->get('/accounting/vouchers/receipt/create')
        ->assertInertia(fn ($page) => $page->where('loans.0.id', $this->loan->id)
            ->where('loans.0.counterparty_id', $this->w->rahim->id)
            ->where('loans.0.live', true)
            ->where('loans.0.account_ids', $this->loan->headIds()));
});
