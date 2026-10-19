<?php

use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Corrections\PurchaseReturnActionPolicy;
use App\Modules\Purchasing\Corrections\SupplierCreditNoteActionPolicy;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\SupplierMoneyWorld;

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): purchase return Reverse (goods back
 * into the same batch at the same cost, refused while a credit note uses it)
 * and supplier credit note Correct and Reverse. Stock, GRNI, AP per supplier
 * and the supplier ledger view tie after each.
 */
beforeEach(function () {
    $this->m = SupplierMoneyWorld::make();
    $this->w = $this->m->w;
    $this->d = $this->m->d;
    // 50 kg of salt at Tk 20 received 10 Oct and billed 12 Oct; 10 kg go back 13 Oct.
    $this->order = $this->d->order([[$this->d->salt, '50', '20.00']]);
    $this->bill = $this->d->bill($this->order);
    $this->return = $this->m->returnGoods($this->order, '10');
    $this->batch = StockBatch::query()->sole();
});

afterEach(fn () => Carbon::setTestNow());

it('reverses a return: the goods go back into the same batch at the same cost, GRNI and the order line are restored, the books tie', function () {
    expect((float) $this->batch->fresh()->current_quantity)->toBe(40.0)
        ->and($this->m->problems())->toBe([]);

    $result = $this->m->reverse($this->return, $this->w->financeB);
    $restored = StockMovement::query()->whereNotNull('reverses_movement_id')->orderByDesc('id')->first();

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-13')
        ->and($this->return->fresh()->isReversed())->toBeTrue()
        ->and((float) $this->batch->fresh()->current_quantity)->toBe(50.0)
        ->and((int) $restored->batch_id)->toBe($this->batch->id)
        ->and((float) $restored->unit_cost)->toBe(20.0)
        ->and($restored->occurred_at->toDateString())->toBe('2026-10-13')
        ->and((float) $this->order->lines()->first()->quantity_returned)->toBe(0.0)
        ->and($this->m->problems())->toBe([]);

    // Reversed once only.
    expect(fn () => $this->m->reverse($this->return->fresh(), $this->w->financeB))->toThrow(CorrectionBlockedException::class);
});

it('refuses while a live credit note uses the return; once the note is reversed it goes through', function () {
    $note = $this->m->creditNote($this->return, $this->bill, '200');

    $availability = app(PurchaseReturnActionPolicy::class)->availability($this->return, $this->w->financeB, DocumentAction::Reverse);
    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('message')->implode(' '))->toContain("Credit note {$note->credit_note_number} credits Purchase return #{$this->return->id}");

    $this->m->reverse($note, $this->w->financeB);
    $this->m->reverse($this->return->fresh(), $this->w->financeB);

    expect($this->return->fresh()->isReversed())->toBeTrue()
        ->and($this->m->problems())->toBe([]);

    // A reversed return can no longer be credited.
    expect(fn () => $this->m->creditNote($this->return->fresh(), $this->bill, '100', 'CN-2'))->toThrow(ValidationException::class, 'was reversed');
});

it('refuses when the batch cost changed since the return', function () {
    $this->batch->forceFill(['unit_cost' => '21.0000'])->saveQuietly();

    $availability = app(PurchaseReturnActionPolicy::class)->availability($this->return, $this->w->financeB, DocumentAction::Reverse);

    expect($availability->allowed)->toBeFalse()
        ->and(collect($availability->blockers)->pluck('code')->all())->toContain('return_stock_moved');
});

it('Correct is not offered on a return', function () {
    $actions = app(PurchaseReturnActionPolicy::class)->actionsFor($this->return, $this->w->financeB);

    expect(collect($actions->actions)->map(fn ($a) => $a->action)->all())->toBe([DocumentAction::Reverse]);
});

it('corrects a credit note: number, date and amount; the bill is owed the difference again; the books tie', function () {
    $note = $this->m->creditNote($this->return, $this->bill, '150', 'CN-1');

    $result = $this->m->correct($note, $this->w->financeB, $this->m->creditForm($note, [
        'credit_note_number' => 'CN-1A',
        'credit_date' => '2026-10-15',
        'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '200']],
    ]));

    expect($result->outcome)->toBe(CorrectionOutcome::Executed)
        ->and($note->fresh()->isReversed())->toBeTrue()
        ->and($result->successor->credit_note_number)->toBe('CN-1A')
        ->and((float) $result->successor->total)->toBe(200.0)
        ->and($result->reversalJournal->journal_date->toDateString())->toBe('2026-10-14')
        ->and($this->bill->fresh()->outstanding()->minor)->toBe(80000)
        ->and($this->m->problems())->toBe([]);
});

it('refuses a corrected credit note above what the return can still be credited', function () {
    $note = $this->m->creditNote($this->return, $this->bill, '150');

    expect(fn () => $this->m->preview($this->m->input($note, DocumentAction::Correct, $this->m->creditForm($note, [
        'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '250']],
    ])), $this->w->financeB))->toThrow(ValidationException::class, 'to credit');
});

it('reverses a credit note on its own date; the number is free for the right one', function () {
    $note = $this->m->creditNote($this->return, $this->bill, '200');

    $this->m->reverse($note, $this->w->financeB);
    $again = $this->m->creditNote($this->return->fresh(), $this->bill->fresh(), '200');

    expect($again->credit_note_number)->toBe($note->credit_note_number)
        ->and(SupplierCreditNote::query()->whereNull('reversed_at')->count())->toBe(1)
        ->and(app(SupplierCreditNoteActionPolicy::class)->availability($note->fresh(), $this->w->financeB, DocumentAction::Reverse)->allowed)->toBeFalse()
        ->and($this->m->problems())->toBe([]);
});

it('the return page offers Reverse and shows the reversal; the credit note Correct page opens pre-filled', function () {
    $note = $this->m->creditNote($this->return, $this->bill, '150');

    $this->actingAs($this->w->financeB)->get(route('purchasing.credit-notes.correct', $note))->assertOk()
        ->assertInertia(fn ($page) => $page->component('purchasing/credit-notes/correct')->where('availability.allowed', true));

    $this->m->reverse($note, $this->w->financeB);
    $this->m->reverse($this->return->fresh(), $this->w->financeB);

    $this->actingAs($this->w->financeB)->get(route('purchasing.returns.show', $this->return))->assertOk()
        ->assertInertia(fn ($page) => $page->where('corrections.type', PurchaseReturnActionPolicy::TYPE)->where('reversal.reason', 'Typed it wrong on the screen'));

    // A reversed credit note's Correct page sends the person back with the reason.
    $this->actingAs($this->w->financeB)->get(route('purchasing.credit-notes.correct', $note))->assertRedirect(route('purchasing.credit-notes.show', $note));
});
