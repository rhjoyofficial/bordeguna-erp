<?php

use App\Modules\Accounting\Corrections\InterBusinessTransferActionPolicy;
use App\Modules\Accounting\Corrections\MoneyTransferActionPolicy;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\MoneyTransfer;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountsWorld;

/**
 * Corrections wave 2, accounts (B7, 24 Oct 2026): money transfers (Correct,
 * Reverse, the duplicate warning) and inter-business transfers (Reverse in
 * both businesses at once).
 */
beforeEach(function () {
    $this->a = AccountsWorld::make();
    $this->w = $this->a->w;
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a transfer: both accounts move back together, the transfer is kept and marked', function () {
    $transfer = $this->a->transfer('3000', '2026-10-15', '20');

    $result = $this->a->submit(MoneyTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, $this->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($transfer->fresh()->isReversed())->toBeTrue()
        ->and($this->w->balance('6051'))->toBe(0)
        ->and(DB::table('journal_lines')->where('money_account_id', $this->w->cash->id)->sum('amount_minor'))->toEqual(0)
        ->and(DB::table('journal_lines')->where('money_account_id', $this->w->bank->id)->sum('amount_minor'))->toEqual(0)
        ->and($this->a->ledgerBalances())->toBeTrue();
});

it('corrects a transfer amount: a new numbered transfer, labelled, the old one kept', function () {
    $transfer = $this->a->transfer('3000');

    $result = $this->a->submit(MoneyTransferActionPolicy::TYPE, $transfer, DocumentAction::Correct, $this->w->owner, [
        'transferred_on' => '2026-10-15', 'from_money_account_id' => $this->w->bank->id, 'to_money_account_id' => $this->w->cash->id,
        'amount' => '2500', 'fee' => '0',
    ]);

    expect($result->successor->transfer_number)->not->toBe($transfer->transfer_number)
        ->and((string) $result->successor->amount)->toBe('2500.00')
        ->and($transfer->fresh()->isReversed())->toBeTrue()
        ->and((int) DB::table('journal_lines')->where('money_account_id', $this->w->cash->id)->sum('amount_minor'))->toBe(250000);
});

it('is the Owner\'s: Finance gets no button and cannot submit', function () {
    $transfer = $this->a->transfer('3000');

    expect(app(MoneyTransferActionPolicy::class)->actionsFor($transfer, $this->w->financeB)->actions)->toBe([])
        ->and(fn () => $this->a->submit(MoneyTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, $this->w->financeB))->toThrow(Exception::class);
});

it('refuses while a money line is matched on the bank statement', function () {
    $transfer = $this->a->transfer('3000');
    $journal = Journal::query()->where('source_type', $transfer->getMorphClass())->where('source_id', $transfer->id)->sole();
    $this->a->matchJournalLine($journal->id);

    $preview = $this->a->preview(MoneyTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, $this->w->owner);

    expect(collect($preview->blockers)->pluck('code')->all())->toContain('bank_matched');
});

it('warns about a second transfer between the same accounts for the same amount', function () {
    $this->a->transfer('3000');
    $form = ['from_money_account_id' => $this->w->bank->id, 'to_money_account_id' => $this->w->cash->id, 'amount' => '3000', 'transferred_on' => '2026-10-16'];

    $this->actingAs($this->w->owner)->post(route('accounting.money-transfers.store'), $form)->assertSessionHasErrors('_duplicate');
    expect(MoneyTransfer::count())->toBe(1);

    $this->actingAs($this->w->owner)->post(route('accounting.money-transfers.store'), $form + ['duplicate_ack' => session('duplicateWarning')['ack_token']])
        ->assertSessionHasNoErrors();
    expect(MoneyTransfer::count())->toBe(2);
});

// ─── Inter-business ─────────────────────────────────────────────────────────

it('reverses an inter-business transfer in both businesses at once', function () {
    $transfer = $this->a->interBusinessTransfer();

    $result = $this->a->submit(InterBusinessTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, $this->w->owner);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($transfer->fresh()->isReversed())->toBeTrue();

    foreach ([$this->w->business->id, $this->w->other->id] as $business) {
        $live = DB::table('journals')->where('business_id', $business)->where('source_type', $transfer->getMorphClass())->where('source_id', $transfer->id)->count();
        $sum = (int) DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.business_id', $business)->where('journals.source_type', $transfer->getMorphClass())->sum('journal_lines.amount_minor');
        expect($live)->toBe(2)->and($sum)->toBe(0);
    }
});

it('refuses when the receiving business has closed that month', function () {
    $transfer = $this->a->interBusinessTransfer();

    TenantScope::enter($this->w->other->id);
    AccountingPeriod::closeThrough(Carbon::parse('2026-10-15'), $this->w->owner, 'Closed there');
    TenantScope::enter($this->w->business->id);

    $preview = $this->a->preview(InterBusinessTransferActionPolicy::TYPE, $transfer, DocumentAction::Reverse, $this->w->owner);

    expect(collect($preview->blockers)->pluck('code')->all())->toContain('receiver_closed')
        ->and($transfer->fresh()->isReversed())->toBeFalse();
});
