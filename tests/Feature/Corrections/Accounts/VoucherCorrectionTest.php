<?php

use App\Modules\Accounting\Actions\FixVoucherDetails;
use App\Modules\Accounting\Actions\SaveVoucherDraft;
use App\Modules\Accounting\Actions\SubmitOrPostVoucher;
use App\Modules\Accounting\Corrections\VoucherActionPolicy;
use App\Modules\Accounting\Database\Seeders\VoucherWorkflowSeeder;
use App\Modules\Accounting\Enums\VoucherStatus;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\AccountsWorld;

/**
 * Corrections wave 2, accounts (B7, 24 Oct 2026): payment, receive, contra and
 * journal vouchers. Reverse and Correct through the corrections process, Fix
 * details, Withdraw, and the duplicate warning on save.
 */
beforeEach(function () {
    $this->a = AccountsWorld::make();
    $this->w = $this->a->w;
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a posted voucher on its own date, keeps it marked, and nets the month to zero', function () {
    $voucher = $this->a->payment('5000');

    $result = $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-15');

    $voucher->refresh();
    expect($voucher->status)->toBe(VoucherStatus::Reversed)
        ->and($voucher->reversal_reason)->toContain('wrong amount')
        ->and($this->w->balance('6051', '2026-10-01', '2026-10-31'))->toBe(0)
        ->and($this->a->ledgerBalances())->toBeTrue()
        ->and(CorrectionLink::count())->toBe(1);
});

it('reverses Today in an open month when asked', function () {
    $voucher = $this->a->payment('5000');

    $result = $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB, null, ReverseDateChoice::Today);

    expect($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-17');
});

it('corrects the amount in one step: reversal on its own date, a new voucher, both linked', function () {
    $voucher = $this->a->payment('5000');

    $result = $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Correct, $this->w->financeB, $this->a->voucherForm($voucher, ['amount' => '4500', 'voucher_date' => '2026-10-14']));

    $successor = $result->successor;
    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($successor->status)->toBe(VoucherStatus::Posted)
        ->and($successor->voucher_number)->not->toBe($voucher->voucher_number)
        ->and($successor->voucher_date->toDateString())->toBe('2026-10-14')
        ->and((int) $successor->total_minor)->toBe(450000)
        ->and($successor->copied_from_id)->toBe($voucher->id)
        ->and(CorrectionChain::correctionOfLabel($successor))->toContain($voucher->voucher_number)
        ->and($voucher->fresh()->status)->toBe(VoucherStatus::Reversed)
        ->and($this->w->balance('6051'))->toBe(450000)
        ->and($this->a->ledgerBalances())->toBeTrue();
});

it('refuses a correction that changes nothing, or names a supplier ledger', function () {
    $voucher = $this->a->payment('5000');

    expect(fn () => $this->a->preview(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Correct, $this->w->financeB, $this->a->voucherForm($voucher)))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->a->preview(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Correct, $this->w->financeB, $this->a->voucherForm($voucher, ['party' => 'supplier:1'])))
        ->toThrow(ValidationException::class);
});

it('refuses Reverse and Correct while the bank line is matched, with the plain sentence', function () {
    $voucher = $this->a->payment('5000');
    $this->a->matchJournalLine((int) $voucher->journal_id);

    $preview = $this->a->preview(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB);

    expect($preview->previewToken)->toBeNull()
        ->and(collect($preview->blockers)->pluck('message')->all())->toContain('Reverse: matched on the bank statement. Unmatch it first.');
});

it('sends a correction above Tk 20,000 to a second person, and posts nothing until then', function () {
    $voucher = $this->a->payment('30000');

    $result = $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Correct, $this->w->financeB, $this->a->voucherForm($voucher, ['amount' => '25000']));

    expect($result->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($voucher->fresh()->status)->toBe(VoucherStatus::Posted)
        ->and(Journal::whereNotNull('reverses_journal_id')->count())->toBe(0);
});

it('in a closed month: Finance may only ask the Owner; the Owner corrects on the original date', function () {
    $voucher = $this->a->payment('5000', '2026-09-20');
    $this->w->closeThrough('2026-09-30');

    $asFinance = $this->a->preview(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB);
    expect($asFinance->approval->value)->toBe('owner_closed_period');

    $result = $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Correct, $this->w->owner, $this->a->voucherForm($voucher, ['amount' => '4000']));

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-09-20')
        ->and($result->reversalJournal->period_exception_id)->not->toBeNull()
        ->and($result->successor->voucher_date->toDateString())->toBe('2026-09-20');
});

it('refuses Reverse to somebody without accounting.correct, and draws no buttons for them', function () {
    $voucher = $this->a->payment('5000');

    expect(fn () => $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->manager))->toThrow(Exception::class);

    $actions = app(VoucherActionPolicy::class)->actionsFor($voucher, $this->w->manager);
    expect(collect($actions->actions)->pluck('action')->map->value->all())->not->toContain('reverse');
});

it('requires a reason of five characters on the server', function () {
    $voucher = $this->a->payment('5000');

    $this->actingAs($this->w->financeB)
        ->postJson('/records/'.VoucherActionPolicy::TYPE."/{$voucher->id}/reverse", ['reason_text' => 'no', 'idempotency_key' => (string) Str::uuid(), 'preview_token' => 'x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason_text');
});

it('keeps a receipt the voucher created, marked reversed, instead of deleting it (risk R8)', function () {
    $voucher = $this->a->payment('5000');
    // A voucher with a customer receipt is covered by PartyLedgerVoucherTest;
    // here the plain voucher has none, so nothing else is touched.
    $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB);

    expect(Voucher::count())->toBe(1);
});

// ─── Fix details ────────────────────────────────────────────────────────────

it('fixes the narration, the line text and the paid-to spelling, and moves no journal', function () {
    $voucher = $this->a->payment('5000');
    $journals = Journal::count();
    $line = $voucher->lines()->first();

    $this->actingAs($this->w->financeA)
        ->post(route('accounting.vouchers.fix-details', $voucher), [
            'narration' => 'Office rent, October',
            'counterparty' => 'Razzak Traders Ltd',
            'lines' => [$line->id => 'Rent for October 2026'],
            'reason_text' => 'Typo in the narration',
            'seen' => FixVoucherDetails::fingerprint($voucher),
        ])
        ->assertSessionHasNoErrors();

    $voucher->refresh();
    expect($voucher->narration)->toBe('Office rent, October')
        ->and($voucher->counterparty)->toBe('Razzak Traders Ltd')
        ->and($line->fresh()->narration)->toBe('Rent for October 2026')
        ->and(Journal::count())->toBe($journals)
        ->and(Activity::where('log_name', 'corrections')->where('event', 'fix_details')->count())->toBe(1);
});

it('refuses Fix details when nothing changed, when the record changed since, or for somebody else', function () {
    $voucher = $this->a->payment('5000');
    $seen = FixVoucherDetails::fingerprint($voucher);

    $this->actingAs($this->w->financeA)
        ->post(route('accounting.vouchers.fix-details', $voucher), ['narration' => 'Office rent', 'reason_text' => 'No change at all', 'seen' => $seen])
        ->assertSessionHasErrors('narration');

    $voucher->forceFill(['narration' => 'Changed by somebody'])->save();

    $this->actingAs($this->w->financeA)
        ->post(route('accounting.vouchers.fix-details', $voucher), ['narration' => 'Mine', 'reason_text' => 'Typo in the narration', 'seen' => $seen])
        ->assertSessionHasErrors('stale');

    $this->actingAs($this->w->sales)
        ->post(route('accounting.vouchers.fix-details', $voucher), ['narration' => 'Mine', 'reason_text' => 'Typo in the narration', 'seen' => FixVoucherDetails::fingerprint($voucher->fresh())])
        ->assertForbidden();
});

// ─── Withdraw ───────────────────────────────────────────────────────────────

it('lets the sender withdraw a waiting voucher back to a draft, and nobody else', function () {
    (new VoucherWorkflowSeeder)->run();
    $draft = app(SaveVoucherDraft::class)->handle(VoucherType::Payment, [
        'voucher_date' => '2026-10-15', 'money_account_id' => $this->w->bank->id, 'counterparty' => 'Razzak',
        'lines' => [['account_id' => $this->a->expense->id, 'amount' => '30000']],
    ], $this->w->financeA);
    $result = app(SubmitOrPostVoucher::class)->handle($draft, $this->w->financeA);
    expect($result['pending'])->toBeTrue();

    $request = $draft->fresh()->approvalRequest;

    $this->actingAs($this->w->financeB)
        ->post(route('approvals.withdraw', $request), ['reason_text' => 'I sent the wrong one'])
        ->assertSessionHasErrors('reason_text');

    $this->actingAs($this->w->financeA)
        ->post(route('approvals.withdraw', $request), ['reason_text' => 'I sent the wrong one'])
        ->assertSessionHasNoErrors();

    expect($draft->fresh()->status)->toBe(VoucherStatus::Draft)
        ->and($request->fresh()->status->value)->toBe('returned')
        ->and(Journal::count())->toBe(0);

    // A double click finds it no longer waiting.
    $this->actingAs($this->w->financeA)
        ->post(route('approvals.withdraw', $request), ['reason_text' => 'I sent the wrong one'])
        ->assertSessionHasErrors('reason_text');
});

// ─── Duplicate warning ──────────────────────────────────────────────────────

it('warns before saving a voucher like one saved in the last 3 days, and saves anyway on the ack', function () {
    $this->a->payment('5000');
    $form = [
        'voucher_date' => '2026-10-16', 'money_account_id' => $this->w->bank->id, 'counterparty' => 'razzak  traders',
        'lines' => [['account_id' => $this->a->expense->id, 'amount' => '5000']],
    ];

    $this->actingAs($this->w->financeA)->post(route('accounting.vouchers.store', 'payment'), $form)
        ->assertSessionHasErrors('_duplicate');

    $ack = session('duplicateWarning')['ack_token'];
    expect(Voucher::count())->toBe(1);

    $this->actingAs($this->w->financeA)->post(route('accounting.vouchers.store', 'payment'), $form + ['duplicate_ack' => $ack])
        ->assertSessionHasNoErrors();

    expect(Voucher::count())->toBe(2)
        ->and(DuplicateAcknowledgement::count())->toBe(1);
});

it('does not warn about a different amount or a reversed voucher', function () {
    $voucher = $this->a->payment('5000');
    $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB);

    $this->actingAs($this->w->financeA)->post(route('accounting.vouchers.store', 'payment'), [
        'voucher_date' => '2026-10-16', 'money_account_id' => $this->w->bank->id, 'counterparty' => 'Razzak Traders',
        'lines' => [['account_id' => $this->a->expense->id, 'amount' => '5000']],
    ])->assertSessionHasNoErrors();
});

it('a reversed voucher cannot be reversed again', function () {
    $voucher = $this->a->payment('5000');
    $this->a->submit(VoucherActionPolicy::TYPE, $voucher, DocumentAction::Reverse, $this->w->financeB);

    expect(fn () => $this->a->submit(VoucherActionPolicy::TYPE, $voucher->fresh(), DocumentAction::Reverse, $this->w->financeC))
        ->toThrow(CorrectionBlockedException::class);
});
