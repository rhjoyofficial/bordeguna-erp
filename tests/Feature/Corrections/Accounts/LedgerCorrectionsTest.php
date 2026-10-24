<?php

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\Actions\ReleaseAmortizations;
use App\Modules\Accounting\Actions\UpdateChequeStatus;
use App\Modules\Accounting\Corrections\ManualJournalActionPolicy;
use App\Modules\Accounting\Corrections\PartyAdjustmentActionPolicy;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Accounting\Models\ChequeStatus;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountsWorld;
use Tests\Support\PartyWorld;

/**
 * Corrections wave 2, accounts (B7, 24 Oct 2026): manual journals, party
 * adjustments, cheque Undo status, bank statement Remove import, and an
 * accrual's Reverse this month.
 */
beforeEach(function () {
    $this->a = AccountsWorld::make();
    $this->w = $this->a->w;

    $this->manual = fn (string $amount = '1000', string $date = '2026-10-10', ?Account $debit = null) => app(PostJournal::class)->handle(new JournalDraft(
        Carbon::parse($date),
        [JournalLineDraft::debit(($debit ?? $this->a->expense)->id, Money::ofTaka($amount), 'Hand entry'), JournalLineDraft::credit(Account::bySlug('cash_in_hand')->id, Money::ofTaka($amount), 'Hand entry')],
        'Petty cash spent',
    ), $this->w->owner);

    $this->journalForm = fn (string $amount, string $date = '2026-10-10') => [
        'journal_date' => $date, 'memo' => 'Petty cash spent', 'journal_type' => 'manual',
        'lines' => [
            ['account_id' => $this->a->expense->id, 'debit' => $amount, 'credit' => '', 'memo' => 'Hand entry'],
            ['account_id' => Account::bySlug('cash_in_hand')->id, 'debit' => '', 'credit' => $amount, 'memo' => 'Hand entry'],
        ],
    ];
});

afterEach(fn () => Carbon::setTestNow());

// ─── Manual journal ─────────────────────────────────────────────────────────

it('reverses a manual journal with a reason, on its own date', function () {
    $journal = ($this->manual)();

    $result = $this->a->submit(ManualJournalActionPolicy::TYPE, $journal, DocumentAction::Reverse, $this->w->owner);

    expect($result->reversalJournal->reverses_journal_id)->toBe($journal->id)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-10')
        ->and($journal->fresh()->hasBeenReversed())->toBeTrue()
        ->and($this->w->balance('6051'))->toBe(0);
});

it('corrects a manual journal: the mirror and a new journal naming what it corrects', function () {
    $journal = ($this->manual)();

    $result = $this->a->submit(ManualJournalActionPolicy::TYPE, $journal, DocumentAction::Correct, $this->w->owner, ($this->journalForm)('800'));

    expect($result->successor)->toBeInstanceOf(Journal::class)
        ->and($result->successor->memo)->toContain("Correction of {$journal->journal_number}")
        ->and($this->w->balance('6051'))->toBe(80000)
        ->and($this->a->ledgerBalances())->toBeTrue();
});

it('refuses a subsystem journal, and a closed month, with a sentence', function () {
    $journal = ($this->manual)('500', '2026-09-10');
    $this->w->closeThrough('2026-09-30');

    $preview = $this->a->preview(ManualJournalActionPolicy::TYPE, $journal, DocumentAction::Reverse, $this->w->owner);
    expect(collect($preview->blockers)->pluck('code')->all())->toContain('manual_closed_month');

    $voucher = $this->a->payment('700');
    $preview = $this->a->preview(ManualJournalActionPolicy::TYPE, Journal::find($voucher->journal_id), DocumentAction::Reverse, $this->w->owner);
    expect(collect($preview->blockers)->pluck('code')->all())->toContain('not_manual');
});

it('is the Owner\'s: Finance draws no button', function () {
    $journal = ($this->manual)();

    expect(app(ManualJournalActionPolicy::class)->actionsFor($journal, $this->w->financeB)->actions)->toBe([]);
});

// ─── Party adjustment ───────────────────────────────────────────────────────

it('reverses a write-off and KEEPS it: the invoice owes again, the document says what it had settled', function () {
    [$invoice, $adjustment] = $this->a->writeOff();
    expect($invoice->fresh()->outstanding()->format())->toBe('6,000.00');

    $result = $this->a->submit(PartyAdjustmentActionPolicy::TYPE, $adjustment, DocumentAction::Reverse, $this->w->owner);

    $adjustment->refresh();
    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(PartyAdjustment::count())->toBe(1)
        ->and($adjustment->isReversed())->toBeTrue()
        ->and($adjustment->reversed_allocations[0]['number'])->toBe('WO-1')
        ->and($invoice->fresh()->outstanding()->format())->toBe('10,000.00')
        ->and($this->a->ledgerBalances())->toBeTrue();
});

it('the old Remove keeps the adjustment too, marked reversed', function () {
    [$invoice, $adjustment] = $this->a->writeOff();

    app(RecordPartyAdjustment::class)->remove($adjustment, $this->w->owner);

    expect(PartyAdjustment::count())->toBe(1)
        ->and($adjustment->fresh()->isReversed())->toBeTrue()
        ->and($invoice->fresh()->outstanding()->format())->toBe('10,000.00');
});

// ─── Cheque: Undo status ────────────────────────────────────────────────────

it('undoes a cleared cheque (pending again) and a bounced one (the payment posts again)', function () {
    $cleared = $this->a->payment('1000', '2026-10-15', null, ['instrument_type' => 'cheque', 'instrument_number' => 'CHQ-1']);
    app(UpdateChequeStatus::class)->handle($cleared, 'cleared', '2026-10-16', null, $this->w->owner);

    $this->actingAs($this->w->owner)->post(route('accounting.cheques.undo', $cleared), ['reason_text' => 'Marked the wrong cheque'])->assertSessionHasNoErrors();
    expect(ChequeStatus::query()->current()->where('voucher_id', $cleared->id)->exists())->toBeFalse();

    $bounced = $this->a->payment('2000', '2026-10-15', null, ['instrument_type' => 'cheque', 'instrument_number' => 'CHQ-2', 'counterparty' => 'Other payee']);
    app(UpdateChequeStatus::class)->handle($bounced, 'bounced', '2026-10-16', null, $this->w->owner);
    expect($bounced->fresh()->status)->toBe(VoucherStatus::Reversed);

    $this->actingAs($this->w->owner)->post(route('accounting.cheques.undo', $bounced), ['reason_text' => 'It never bounced'])->assertSessionHasNoErrors();

    $copy = Voucher::query()->where('copied_from_id', $bounced->id)->sole();
    expect($copy->status)->toBe(VoucherStatus::Posted)
        ->and($copy->voucher_date->toDateString())->toBe('2026-10-15')
        ->and($copy->instrument_number)->toBe('CHQ-2')
        ->and($this->a->ledgerBalances())->toBeTrue();

    // It can be marked again later.
    app(UpdateChequeStatus::class)->handle($cleared->fresh(), 'cleared', '2026-10-17', null, $this->w->owner);
    expect(ChequeStatus::query()->current()->where('voucher_id', $cleared->id)->value('status'))->toBe('cleared');
});

it('lets only the Owner undo a cheque status', function () {
    $voucher = $this->a->payment('1000', '2026-10-15', null, ['instrument_type' => 'cheque', 'instrument_number' => 'CHQ-3']);
    app(UpdateChequeStatus::class)->handle($voucher, 'cleared', '2026-10-16', null, $this->w->owner);

    // Finance is refused (by the route or by the action), and nothing changes.
    $response = $this->actingAs($this->w->financeB)->post(route('accounting.cheques.undo', $voucher), ['reason_text' => 'Marked the wrong cheque']);
    expect(in_array($response->status(), [302, 403], true))->toBeTrue()
        ->and(ChequeStatus::query()->current()->where('voucher_id', $voucher->id)->value('status'))->toBe('cleared');
});

// ─── Bank statement: Remove import ──────────────────────────────────────────

it('removes an unmatched import and refuses one with a matched line', function () {
    $import = DB::table('bank_statement_imports')->insertGetId([
        'business_id' => $this->w->business->id, 'money_account_id' => $this->w->bank->id, 'filename' => 'oct.csv', 'file_sha256' => str_repeat('a', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('bank_statement_lines')->insert([
        'business_id' => $this->w->business->id, 'import_id' => $import, 'money_account_id' => $this->w->bank->id,
        'txn_date' => '2026-10-15', 'amount' => 100, 'match_status' => 'unmatched', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->w->financeB)->post(route('accounting.bank-statements.remove-import', $import), ['reason_text' => 'Imported the wrong file'])
        ->assertSessionHasNoErrors();

    expect(DB::table('bank_statement_lines')->where('import_id', $import)->count())->toBe(0)
        ->and(DB::table('bank_statement_imports')->where('id', $import)->value('removed_at'))->not->toBeNull();

    $voucher = $this->a->payment('500');
    $matched = $this->a->matchJournalLine((int) $voucher->journal_id);
    $other = DB::table('bank_statement_lines')->where('id', $matched)->value('import_id');

    $this->actingAs($this->w->financeB)->post(route('accounting.bank-statements.remove-import', $other), ['reason_text' => 'Imported the wrong file'])
        ->assertSessionHasErrors('reason_text');
    $this->actingAs($this->w->financeB)->post(route('accounting.bank-statements.remove-line', $matched), ['reason_text' => 'Imported the wrong file'])
        ->assertSessionHasErrors('reason_text');
    expect(DB::table('bank_statement_lines')->where('id', $matched)->exists())->toBeTrue();
});

// ─── Accrual: Reverse this month ────────────────────────────────────────────

it('reverses the latest released month of a schedule and stops it', function () {
    $prepaid = Account::firstOrCreate(['code' => '1189'], ['name' => 'Prepaid test', 'type' => 'asset', 'is_postable' => true]);
    $schedule = AmortizationSchedule::create([
        'business_id' => $this->w->business->id, 'kind' => 'prepaid_expense', 'name' => 'Hosting', 'source_account_id' => $prepaid->id,
        'target_account_id' => $this->a->expense->id, 'total_amount' => '1200', 'start_month' => '2026-09-01', 'months' => 12, 'created_by' => $this->w->owner->id,
    ]);
    app(ReleaseAmortizations::class)->through('2026-10-17');
    expect($schedule->fresh()->released_through->toDateString())->toBe('2026-10-01');

    $this->actingAs($this->w->owner)->post(route('accounting.amortizations.reverse-month', $schedule), ['reason_text' => 'Schedule set up wrong'])
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    expect($schedule->released_through->toDateString())->toBe('2026-09-01')
        ->and($schedule->status)->toBe('cancelled')
        ->and($this->w->balance('6051'))->toBe(10000);
});

it('refuses a reason shorter than five characters for every new action', function () {
    $voucher = $this->a->payment('1000', '2026-10-15', null, ['instrument_type' => 'cheque', 'instrument_number' => 'CHQ-9']);
    app(UpdateChequeStatus::class)->handle($voucher, 'cleared', '2026-10-16', null, $this->w->owner);

    $this->actingAs($this->w->owner)->post(route('accounting.cheques.undo', $voucher), ['reason_text' => 'oops'])->assertSessionHasErrors('reason_text');
});

// ─── Set-off: Withdraw ──────────────────────────────────────────────────────

it('lets the sender of a waiting set-off withdraw it; it posts nothing and frees its bills', function () {
    $p = PartyWorld::make($this->w);
    $bill = $p->bill('5000');
    $invoice = $p->invoice('5000');
    $setOff = $p->raise([$bill->id => '100'], [$invoice->id => '100']);
    $request = $setOff->fresh()->approvalRequest;

    $this->actingAs($this->w->financeA)
        ->post(route('approvals.withdraw', $request), ['reason_text' => 'Raised against the wrong bill'])
        ->assertSessionHasNoErrors();

    expect($setOff->fresh()->status)->toBe(SetOff::WITHDRAWN)
        ->and($request->fresh()->status->value)->toBe('returned')
        ->and(Journal::query()->where('source_type', $setOff->getMorphClass())->count())->toBe(0);
});
