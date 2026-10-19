<?php

use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CorrectionOutcome;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Inventory\Corrections\OpeningStockActionPolicy;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\StockWorld;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): opening stock is corrected into
 * Opening Balance Equity on the opening date (FINAL-PLAN 3.3, 4.2), never
 * into Inventory Write-offs, and never by entering it a second time.
 *
 * The trigger case: 10 jars missed in the opening count of 31 Aug.
 */
beforeEach(function () {
    $this->s = StockWorld::make();
    $this->w = $this->s->w;
    // Small enough that a correction stays under the Tk 20,000 second-person
    // limit (judged on the whole document); one test goes over it.
    $this->opening = $this->s->opening([[$this->s->honey, '10', '500', 'H-01'], [$this->s->salt, '100', '60']], by: $this->s->staff);
    $this->lines = $this->opening->lines->keyBy('product_id');
});

afterEach(fn () => Carbon::setTestNow());

/** The Correct form as the page sends it, with changes per product id and new lines. */
function openingForm(OpeningStock $opening, array $changes = [], array $new = []): array
{
    $opening->loadMissing('lines');

    return [
        'note' => $opening->note,
        'lines' => [
            ...$opening->lines->map(fn ($line) => array_merge([
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'quantity' => (string) (float) $line->quantity,
                'unit_cost' => (string) (float) $line->unit_cost,
                'batch_number' => $line->batch_number,
                'expiry_date' => $line->expiry_date?->toDateString(),
            ], $changes[$line->product_id] ?? []))->values()->all(),
            ...$new,
        ],
    ];
}

function correctOpening(StockWorld $s, OpeningStock $opening, array $form, $by)
{
    return $s->submit($s->input(OpeningStockActionPolicy::TYPE, $opening, DocumentAction::Correct, $form, 'Missed a shelf in the count'), $by);
}

it('adds 10 jars missed in the opening count into Opening Balance Equity, not profit', function () {
    $this->s->sell($this->s->honey, '6', '2026-09-10');
    $before = ['equity' => $this->s->balance('opening_balance_equity'), 'writeoff' => $this->s->balance('inventory_write_off')];

    $result = correctOpening($this->s, $this->opening, openingForm($this->opening, [$this->s->honey->id => ['quantity' => '20']]), $this->w->manager);

    expect($result->outcome)->toBe(CorrectionOutcome::Executed);

    // The same batch holds the 10 more, dated on the opening day.
    $batch = StockBatch::where('batch_number', 'H-01')->sole();
    expect($batch->current_quantity)->toBe('14.0000')
        ->and($batch->initial_quantity)->toBe('20.0000')
        ->and($this->s->onHand($this->s->honey))->toBe('14.0000');

    // Tk 5,000 more stock and Tk 5,000 more opening equity; no write-off or gain.
    expect($this->s->balance('opening_balance_equity') - $before['equity'])->toBe(-500000)
        ->and($this->s->balance('inventory_write_off'))->toBe($before['writeoff']);

    $successor = OpeningStock::where('corrects_id', $this->opening->id)->sole();
    $journals = Journal::where('source_type', $successor->getMorphClass())->whereIn('source_id', [$this->opening->id, $successor->id])->get();

    expect($journals)->toHaveCount(3)
        ->and($journals->every(fn ($j) => $j->journal_date->toDateString() === '2026-08-31'))->toBeTrue()
        ->and($journals->every(fn ($j) => $j->journal_type === JournalType::OpeningBalance))->toBeTrue();

    // The original stays, marked; the successor is the warehouse's live one.
    expect($this->opening->fresh()->isReversed())->toBeTrue()
        ->and($this->opening->fresh()->live_warehouse_id)->toBeNull()
        ->and($successor->live_warehouse_id)->toBe($this->s->savar->id)
        ->and(CorrectionLink::where('original_id', $this->opening->id)->sole()->successor_number)->toBe("Opening stock #{$successor->id}");

    $this->s->assertReconciles();
});

it('adds a forgotten product as a new opening batch', function () {
    correctOpening($this->s, $this->opening, openingForm($this->opening, [], [
        ['line_id' => null, 'product_id' => $this->s->ghee->id, 'quantity' => '4', 'unit_cost' => '900', 'batch_number' => null, 'expiry_date' => null],
    ]), $this->w->manager);

    $successor = OpeningStock::where('corrects_id', $this->opening->id)->sole();
    expect($successor->lines)->toHaveCount(3)
        ->and($this->s->onHand($this->s->ghee))->toBe('4.0000')
        ->and(StockBatch::where('product_id', $this->s->ghee->id)->sole()->received_at->toDateString())->toBe('2026-08-31');

    $this->s->assertReconciles();
});

it('brings a count down only as far as the batch never went below since the opening', function () {
    $this->s->sell($this->s->salt, '95', '2026-09-10');
    $form = fn (string $q) => openingForm($this->opening, [$this->s->salt->id => ['quantity' => $q]]);

    try {
        $this->s->preview($this->s->input(OpeningStockActionPolicy::TYPE, $this->opening, DocumentAction::Correct, $form('90')), $this->w->manager);
        $this->fail('The count came down below what was sold.');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->first())->toContain('never held fewer than 5.0000');
    }

    correctOpening($this->s, $this->opening, $form('96'), $this->w->manager);

    expect($this->s->onHand($this->s->salt))->toBe('1.0000');
    $this->s->assertReconciles();
});

it('changes a cost only while the batch is untouched', function () {
    correctOpening($this->s, $this->opening, openingForm($this->opening, [$this->s->salt->id => ['unit_cost' => '65']]), $this->w->manager);

    expect(StockBatch::where('product_id', $this->s->salt->id)->sole()->unit_cost)->toBe('65.0000')
        ->and($this->s->balance('opening_balance_equity'))->toBe(-(500000 + 650000));
    $this->s->assertReconciles();

    $successor = OpeningStock::where('corrects_id', $this->opening->id)->sole();
    $this->s->sell($this->s->honey, '1', '2026-09-10');

    expect(fn () => $this->s->preview($this->s->input(OpeningStockActionPolicy::TYPE, $successor, DocumentAction::Correct, openingForm($successor, [$this->s->honey->id => ['unit_cost' => '520']])), $this->w->manager))
        ->toThrow(ValidationException::class, 'sold or moved since the opening');
});

it('fixes a batch number and expiry on the batch itself', function () {
    correctOpening($this->s, $this->opening, openingForm($this->opening, [$this->s->honey->id => ['batch_number' => 'H-02', 'expiry_date' => '2028-03-31']]), $this->w->manager);

    $batch = StockBatch::where('product_id', $this->s->honey->id)->sole();
    expect($batch->batch_number)->toBe('H-02')
        ->and($batch->expiry_date->toDateString())->toBe('2028-03-31')
        ->and($this->s->balance('opening_balance_equity'))->toBe(-1100000);
});

it('still refuses a second ordinary opening entry, and corrects a correction but never the original twice', function () {
    correctOpening($this->s, $this->opening, openingForm($this->opening, [$this->s->honey->id => ['quantity' => '12']]), $this->w->manager);

    expect(fn () => $this->s->opening([[$this->s->ghee, '1', '1']]))->toThrow(ValidationException::class, 'already been recorded');

    expect(fn () => correctOpening($this->s, $this->opening->fresh(), openingForm($this->opening->fresh(), [$this->s->honey->id => ['quantity' => '13']]), $this->w->manager))
        ->toThrow(CorrectionBlockedException::class);

    $successor = OpeningStock::where('corrects_id', $this->opening->id)->sole();
    correctOpening($this->s, $successor, openingForm($successor, [$this->s->honey->id => ['quantity' => '13']]), $this->w->manager);

    expect($this->s->onHand($this->s->honey))->toBe('13.0000')
        ->and(OpeningStock::query()->live()->count())->toBe(1);
    $this->s->assertReconciles();
});

it('is for the people who record opening stock', function () {
    expect(app(OpeningStockActionPolicy::class)->actionsFor($this->opening, $this->w->financeB)->actions)->toBe([]);

    expect(fn () => $this->s->preview($this->s->input(OpeningStockActionPolicy::TYPE, $this->opening, DocumentAction::Correct, openingForm($this->opening, [$this->s->honey->id => ['quantity' => '11']])), $this->w->financeB))
        ->toThrow(AuthorizationException::class);
});

it('in a closed opening month only the Owner corrects, on the opening date', function () {
    $this->w->closeThrough('2026-09-30');
    $form = openingForm($this->opening, [$this->s->honey->id => ['quantity' => '15']]);

    $asked = correctOpening($this->s, $this->opening, $form, $this->w->manager);
    expect($asked->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($this->s->onHand($this->s->honey))->toBe('10.0000');

    $this->actingAs($this->w->owner)->post(route('corrections.requests.approve', $asked->request))->assertSessionHas('success');

    $successor = OpeningStock::where('corrects_id', $this->opening->id)->sole();
    $journal = Journal::where('source_type', $successor->getMorphClass())->where('source_id', $successor->id)->sole();

    expect($this->s->onHand($this->s->honey))->toBe('15.0000')
        ->and($journal->journal_date->toDateString())->toBe('2026-08-31')
        ->and($journal->period_exception_id)->not->toBeNull()
        ->and(AccountingPeriod::current()->is_unlocked)->toBeFalse();
    $this->s->assertReconciles();
});

it('sends a large opening stock correction to a second person', function () {
    $form = openingForm($this->opening, [$this->s->honey->id => ['quantity' => '40']]);

    $asked = correctOpening($this->s, $this->opening, $form, $this->w->manager);

    expect($asked->outcome)->toBe(CorrectionOutcome::Requested)
        ->and($this->s->onHand($this->s->honey))->toBe('10.0000');

    $this->actingAs($this->w->financeC)->post(route('corrections.requests.approve', $asked->request))->assertSessionHas('success');

    expect($this->s->onHand($this->s->honey))->toBe('40.0000');
    $this->s->assertReconciles();
});

it('refuses a form that changes nothing, drops a line, or swaps a line\'s product', function () {
    $preview = fn (array $form) => $this->s->preview($this->s->input(OpeningStockActionPolicy::TYPE, $this->opening, DocumentAction::Correct, $form), $this->w->manager);

    expect(fn () => $preview(openingForm($this->opening)))->toThrow(ValidationException::class, 'Nothing was changed');

    $dropped = openingForm($this->opening);
    array_pop($dropped['lines']);
    expect(fn () => $preview($dropped))->toThrow(ValidationException::class, 'stays on the form');

    expect(fn () => $preview(openingForm($this->opening, [$this->s->salt->id => ['product_id' => $this->s->ghee->id]])))->toThrow(ValidationException::class, 'keeps its product');
});
