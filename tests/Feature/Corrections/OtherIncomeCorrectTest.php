<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\CorrectionService;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Corrections\Exceptions\PossibleDuplicateException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CorrectionWorld;

/**
 * Other income, Correct (PHASE-0-CONTRACT v2, 3.7 tests 8 to 11, and the
 * worked example of 3.4): reversal + successor in ONE transaction.
 */
beforeEach(function () {
    $this->w = CorrectionWorld::make();
});

afterEach(fn () => Carbon::setTestNow());

it('8. posts the reversal and the successor in one go: new number, "Correction of" label, link, journals (the 3.4 worked example)', function () {
    $original = $this->w->record('5000', '2026-10-15');
    $originalJournal = Journal::find($original->journal_id);

    $result = $this->w->correct($original, $this->w->financeB, [
        'account_id' => $this->w->interest->id,
        'amount' => '4500',
        'received_on' => '2026-10-14',
    ]);

    $successor = $result->successor;
    $link = $result->link;

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($successor->voucher_number)->toBe('INC-2026-27-0002')
        ->and($successor->received_on->toDateString())->toBe('2026-10-14')
        ->and($result->message)->toBe("{$original->voucher_number} corrected. The new record is INC-2026-27-0002.")
        ->and(CorrectionChain::correctionOfLabel($successor))->toBe("Correction of {$original->voucher_number}")
        ->and($link->original_journal_id)->toBe($originalJournal->id)
        ->and($link->successor_number)->toBe('INC-2026-27-0002')
        ->and($link->original_minor)->toBe(500000)
        ->and($link->successor_minor)->toBe(450000)
        ->and($link->date_rule)->toBe('original');

    // The reversal: on the ORIGINAL date (15 Oct), mirroring the original.
    $reversal = $result->reversalJournal;
    expect($reversal->journal_date->toDateString())->toBe('2026-10-15')
        ->and($reversal->reverses_journal_id)->toBe($originalJournal->id)
        ->and($reversal->memo)->toStartWith("Reversal of {$original->voucher_number}:");

    // The successor: 14 Oct, interest, its own journal and memo.
    $successorJournal = Journal::find($successor->journal_id);
    expect($successorJournal->journal_date->toDateString())->toBe('2026-10-14')
        ->and($successorJournal->memo)->toContain("Correction of {$original->voucher_number}");

    expect($this->w->balance('11201'))->toBe(450000)
        ->and($this->w->balance('4910'))->toBe(0)
        ->and($this->w->balance('4920'))->toBe(-450000)
        ->and($original->fresh()->correctionStatus())->toBe('corrected')
        ->and(IncomeVoucher::live()->count())->toBe(1);
});

it('9. a failure inside the successor rolls everything back: no reversal, no number used, original unchanged', function () {
    $original = $this->w->record('5000', '2026-10-15');
    $before = Journal::count();

    // A sales head is refused by RecordIncomeVoucher inside the transaction.
    $input = $this->w->input($original, DocumentAction::Correct, $this->w->replacement($original, ['account_id' => Account::bySlug('sales_revenue')->id, 'amount' => '4000']));
    $preview = $this->w->preview($input, $this->w->financeB);

    expect(fn () => app(CorrectionService::class)->submit($input, $this->w->financeB, $preview->previewToken))
        ->toThrow(ValidationException::class);

    // An inactive money account: refused the same way.
    $this->w->cash->update(['is_active' => false]);
    $input2 = $this->w->input($original, DocumentAction::Correct, $this->w->replacement($original, ['money_account_id' => $this->w->cash->id]));

    expect(fn () => $this->w->submit($input2, $this->w->financeB))->toThrow(ValidationException::class);

    expect(Journal::count())->toBe($before)
        ->and($original->fresh()->isReversed())->toBeFalse()
        ->and(CorrectionLink::count())->toBe(0)
        ->and(IncomeVoucher::count())->toBe(1);

    // No number was used up: the next voucher is 0002.
    expect($this->w->record('100')->voucher_number)->toBe('INC-2026-27-0002');
});

it('10. refuses a successor in the future; a successor moved to another open day needs no pass', function () {
    $original = $this->w->record('5000', '2026-10-15');

    $future = $this->w->preview($this->w->input($original, DocumentAction::Correct, $this->w->replacement($original, ['received_on' => '2026-10-20', 'amount' => '4500'])), $this->w->financeB);
    expect(collect($future->blockers)->pluck('code')->all())->toContain('future_date');

    $result = $this->w->correct($original, $this->w->financeB, ['received_on' => '2026-10-02', 'amount' => '4500']);

    expect($result->link->closed_period_exception)->toBeFalse()
        ->and(Journal::find($result->successor->journal_id)->period_exception_id)->toBeNull()
        ->and($result->successor->received_on->toDateString())->toBe('2026-10-02');
});

it('11. duplicates: the original is ignored, another similar voucher warns, a foreign or changed ack is refused, a new candidate asks again, Save anyway is kept', function () {
    $original = $this->w->record('5000', '2026-10-15');

    // Correcting to the same head and amount on another date: its own
    // original never counts as a duplicate.
    $result = $this->w->correct($original, $this->w->financeB, ['received_on' => '2026-10-14']);
    expect($result->outcome)->toBe(CorrectionOutcome::Executed);

    // Another similar voucher exists (Interest, 4,500, 13 Oct).
    $similar = $this->w->record('4500', '2026-10-13', head: $this->w->interest);
    $target = $this->w->record('9000', '2026-10-15');
    $input = $this->w->input($target, DocumentAction::Correct, $this->w->replacement($target, ['account_id' => $this->w->interest->id, 'amount' => '4500']));

    $preview = $this->w->preview($input, $this->w->financeB);
    expect($preview->duplicate)->not->toBeNull()
        ->and($preview->duplicate->candidates[0]->number)->toBe($similar->voucher_number);

    $service = app(CorrectionService::class);

    // No ack: refused with a new ack token.
    try {
        $service->submit($input, $this->w->financeB, $preview->previewToken);
        $this->fail('expected a duplicate warning');
    } catch (PossibleDuplicateException $e) {
        $ack = $e->warning->ackToken;
    }

    // Another user's ack is refused.
    $previewC = $this->w->preview($input, $this->w->financeC);
    expect(fn () => $service->submit($input, $this->w->financeC, $previewC->previewToken, $ack))->toThrow(PossibleDuplicateException::class);

    // An ack for a different payload is refused.
    $changed = $this->w->input($target, DocumentAction::Correct, $this->w->replacement($target, ['account_id' => $this->w->interest->id, 'amount' => '4500', 'received_on' => '2026-10-12']), key: $input->idempotencyKey);
    $previewChanged = $this->w->preview($changed, $this->w->financeB);
    expect(fn () => $service->submit($changed, $this->w->financeB, $previewChanged->previewToken, $ack))->toThrow(PossibleDuplicateException::class);

    // A NEW candidate since the warning: asked again.
    $this->w->record('4500', '2026-10-16', head: $this->w->interest);
    $previewAgain = $this->w->preview($input, $this->w->financeB);
    expect(fn () => $service->submit($input, $this->w->financeB, $previewAgain->previewToken, $ack))->toThrow(PossibleDuplicateException::class);

    // A fresh ack for exactly this payload and candidate set: saved, and kept.
    try {
        $service->submit($input, $this->w->financeB, $previewAgain->previewToken);
    } catch (PossibleDuplicateException $e) {
        $fresh = $e->warning->ackToken;
    }

    $done = $service->submit($input, $this->w->financeB, $previewAgain->previewToken, $fresh);

    expect($done->outcome)->toBe(CorrectionOutcome::Executed)
        ->and(DuplicateAcknowledgement::count())->toBe(1)
        ->and(DuplicateAcknowledgement::first()->subject_id)->toBe($done->successor->id)
        ->and(DuplicateAcknowledgement::first()->user_id)->toBe($this->w->financeB->id);
});

it('refuses a Correct that changes nothing', function () {
    $original = $this->w->record('5000', '2026-10-15');

    expect(fn () => $this->w->preview($this->w->input($original, DocumentAction::Correct, $this->w->replacement($original)), $this->w->financeB))
        ->toThrow(ValidationException::class);
});

it('47. a successor can be corrected later, and its original can never be reversed a second time', function () {
    $a = $this->w->record('5000', '2026-10-15');
    $b = $this->w->correct($a, $this->w->financeB, ['amount' => '4500'])->successor;
    $c = $this->w->correct($b, $this->w->financeC, ['amount' => '4000'])->successor;

    expect(CorrectionLink::count())->toBe(2)
        ->and(CorrectionChain::root($c)->is($a))->toBeTrue()
        ->and(CorrectionChain::latest($a)->is($c))->toBeTrue()
        ->and(count(CorrectionChain::links($b)))->toBe(2)
        ->and(CorrectionChain::correctionOfLabel($c))->toBe("Correction of {$b->voucher_number}")
        ->and($this->w->balance('11201'))->toBe(400000);

    expect(fn () => $this->w->reverse($a->fresh(), $this->w->financeC))->toThrow(CorrectionBlockedException::class)
        ->and(fn () => $this->w->reverse($b->fresh(), $this->w->financeC))->toThrow(CorrectionBlockedException::class);

    expect($this->w->reverse($c->fresh(), $this->w->financeC)->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($this->w->balance('11201'))->toBe(0);
});
