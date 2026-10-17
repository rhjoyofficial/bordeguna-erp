<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Actions\ReverseProductionRun;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing phase 1 acceptance: each test here maps to a numbered item in
 * MANUFACTURING-PHASE1-PACKING-ARCHITECTURE.md ("Acceptance").
 *
 * ⚠ Economic assertions, not just balanced journals - the ledger balances of
 * the named accounts are asserted in paisa, and the batch layer alongside.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-17 10:00:00');

    $this->business = Business::create(['name' => 'Pack Co', 'slug' => 'pack-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $this->piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Factory', 'code' => 'FAC']);

    $product = fn (string $sku, string $name, UnitOfMeasure $unit, array $extra = []) => Product::create(array_merge([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'name' => $name,
        'type' => ProductType::RawMaterial,
    ], $extra));

    $this->oil = $product('OIL-BULK', 'Bulk mustard oil', $this->kg);
    $this->bottle = $product('BTL-5L', '5 L bottle', $this->piece);
    $this->cap = $product('CAP-38', '38 mm cap', $this->piece);
    $this->pack = $product('OIL-5L', 'Mustard oil 5 L pack', $this->piece, ['type' => ProductType::FinishedGood]);

    $this->stockIn = fn (Product $p, string $qty, string $cost) => app(RecordStockIn::class)->handle(
        product: $p,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->user,
        occurredAt: '2026-08-16 09:00:00',
    );

    // The standard fixture: enough for acceptance 1's exact numbers.
    $this->fillShelves = function () {
        ($this->stockIn)($this->oil, '200', '180.00');
        ($this->stockIn)($this->bottle, '50', '15.00');
        ($this->stockIn)($this->cap, '50', '2.00');
    };

    $this->draft = fn (array $overrides = []) => app(RecordProductionRun::class)->draft(
        warehouse: $overrides['warehouse'] ?? $this->warehouse,
        outputProduct: $overrides['output'] ?? $this->pack,
        outputQuantity: $overrides['outputQuantity'] ?? '20',
        lines: $overrides['lines'] ?? [
            ['product' => $this->oil, 'quantity' => '100', 'waste' => $overrides['oilWaste'] ?? '0'],
            ['product' => $this->bottle, 'quantity' => '20'],
            ['product' => $this->cap, 'quantity' => '20'],
        ],
        createdBy: $this->user,
        occurredAt: $overrides['occurredAt'] ?? null,
        outputExpiryDate: $overrides['expiry'] ?? '2027-08-17',
        outputMrp: $overrides['mrp'] ?? null,
        expenseWaste: $overrides['expenseWaste'] ?? false,
        note: $overrides['note'] ?? null,
    );

    $this->confirm = fn (ProductionRun $run) => app(RecordProductionRun::class)->confirm($run, $this->user);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');

    $this->runJournals = fn (ProductionRun $run) => Journal::query()
        ->where('source_type', $run->getMorphClass())
        ->where('source_id', $run->id)
        ->get();
});

// Acceptance 1 (C-MFG-3, C-MFG-5): the whole consumed value lands in the batch,
// and the ledger has nothing to say.
it('costs the output batch at consumed value over output quantity and posts nothing when fully absorbed', function () {
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)());

    // 100 kg x 180 + 20 x 15 + 20 x 2 = 18,340.00 over 20 packs = 917.0000.
    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and((string) $run->consumed_value)->toBe('18340.0000')
        ->and((string) $run->outputBatch->unit_cost)->toBe('917.0000')
        ->and((string) $run->outputBatch->initial_quantity)->toBe('20.0000');

    // No journal exists, and Stock on Hand never moved (C-MFG-5).
    expect(($this->runJournals)($run))->toHaveCount(0)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(0);

    // The batch layer agrees: the drum lost 100 kg, the packs exist.
    expect((string) $this->oil->fresh()->currentStockQuantity())->toBe('100')
        ->and((string) $this->pack->fresh()->currentStockQuantity())->toBe('20');
});

// Acceptance 2 (C-MFG-4): the explicit override, through the mapping key.
it('expenses abnormal waste to the wastage account and carries the rest into the output cost', function () {
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)(['oilWaste' => '2', 'expenseWaste' => true]));

    // Waste share: 2 kg at the line's average consumed cost of 180 = 360.00.
    expect((string) $run->waste_value)->toBe('360.0000')
        ->and((string) $run->outputBatch->unit_cost)->toBe('899.0000'); // (18,340 - 360) / 20

    // Exactly Dr wastage 360.00 / Cr Stock on Hand 360.00, nothing else.
    $journal = ($this->runJournals)($run)->sole();

    expect($journal->balance()->isZero())->toBeTrue()
        ->and(($this->balanceOn)('inventory_write_off'))->toBe(36000)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(-36000)
        ->and(($this->balanceOn)('rounding_difference'))->toBe(0);
});

// Acceptance 3 (C-MFG-8): all or nothing, with the failing line LAST.
it('refuses to confirm when any input line exceeds stock and consumes nothing', function () {
    ($this->stockIn)($this->oil, '200', '180.00');
    ($this->stockIn)($this->bottle, '50', '15.00');
    ($this->stockIn)($this->cap, '10', '2.00'); // 10 caps for a 20-cap run.

    $run = ($this->draft)();

    expect(fn () => ($this->confirm)($run))->toThrow(InsufficientStockException::class);

    // Atomicity: the oil and bottles consumed before the caps failed rolled back.
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Draft)
        ->and((string) $this->oil->fresh()->currentStockQuantity())->toBe('200')
        ->and((string) $this->bottle->fresh()->currentStockQuantity())->toBe('50')
        ->and(StockMovement::where('reason', MovementReason::Production)->count())->toBe(0);
});

// Acceptance 4 (C-MFG-7): FIFO portions reproduce the cost to the penny.
it('consumes FIFO across cost layers and the output cost reproduces the order exactly', function () {
    ($this->stockIn)($this->oil, '60', '100.00');
    Carbon::setTestNow('2026-08-17 10:00:01'); // a later receipt, unambiguously.
    ($this->stockIn)($this->bottle, '50', '0.00');
    ($this->stockIn)($this->cap, '50', '0.00');
    app(RecordStockIn::class)->handle(
        product: $this->oil, warehouse: $this->warehouse, quantity: '60', unitCost: '120.00',
        performedBy: $this->user,
    );

    $run = ($this->confirm)(($this->draft)());

    // 60 kg @ 100 + 40 kg @ 120 = 10,800.00 over 20 packs = 540.0000.
    expect((string) $run->consumed_value)->toBe('10800.0000')
        ->and((string) $run->outputBatch->unit_cost)->toBe('540.0000');

    $oilPortions = $run->movements()
        ->where('product_id', $this->oil->id)
        ->orderBy('id')
        ->get();

    expect($oilPortions)->toHaveCount(2)
        ->and((string) $oilPortions[0]->quantity)->toBe('60.0000')
        ->and((string) $oilPortions[0]->unit_cost)->toBe('100.0000')
        ->and((string) $oilPortions[1]->quantity)->toBe('40.0000')
        ->and((string) $oilPortions[1]->unit_cost)->toBe('120.0000');
});

// Acceptance 5 (C-MFG-8): the correction path.
it('reverses a run by restoring every consumed portion and consuming the produced batch', function () {
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)(['oilWaste' => '2', 'expenseWaste' => true]));
    $reversed = app(ReverseProductionRun::class)->handle($run, $this->user);

    expect($reversed->status)->toBe(ProductionRunStatus::Reversed)
        ->and((string) $this->oil->fresh()->currentStockQuantity())->toBe('200')
        ->and((string) $this->bottle->fresh()->currentStockQuantity())->toBe('50')
        ->and((string) $this->pack->fresh()->currentStockQuantity())->toBe('0');

    // The journal was reversed, so every account nets to zero again.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(0)
        ->and(($this->balanceOn)('inventory_write_off'))->toBe(0);

    // The §10 auditability rule: every reversal movement states what it undoes.
    // 3 restored portions (oil, bottle, cap) + 1 taking the pack layer back out.
    $mirrors = $reversed->movements()->whereNotNull('reverses_movement_id')->get();
    expect($mirrors)->toHaveCount(4);
});

it('refuses to reverse once the produced batch has been partly consumed', function () {
    ($this->fillShelves)();
    $run = ($this->confirm)(($this->draft)());

    // Somebody sells (here: uses) one pack out of the produced batch.
    app(RecordStockOut::class)->handle(
        product: $this->pack, warehouse: $this->warehouse, quantity: '1', performedBy: $this->user,
    );

    expect(fn () => app(ReverseProductionRun::class)->handle($run->fresh(), $this->user))
        ->toThrow(DomainException::class, 'partly sold or consumed');
});

// Acceptance 6 (C-MFG-2, D-MFG-5): flag, never a refusal; unknown content, no flag.
it('flags a deviating yield when the output declares its content and still confirms', function () {
    $this->pack->update(['content_quantity' => '5', 'content_unit_id' => $this->kg->id]);

    ($this->fillShelves)();

    // 20 packs x 5 kg = 100 expected; 110 consumed net of no waste = +10%.
    $run = ($this->confirm)(($this->draft)(['lines' => [
        ['product' => $this->oil, 'quantity' => '110'],
        ['product' => $this->bottle, 'quantity' => '20'],
        ['product' => $this->cap, 'quantity' => '20'],
    ]]));

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and($run->yield_check['checked'])->toBeTrue()
        ->and($run->yield_check['flagged'])->toBeTrue()
        // ⚠ Loose on purpose: a whole-number float round-trips through the JSON
        // column as an int, and 10 == 10.0 is the honest comparison here.
        ->and((float) $run->yield_check['deviation_percent'])->toBe(10.0)
        ->and($run->yieldFlagged())->toBeTrue();
});

it('records waste as part of the explanation the yield check accepts', function () {
    $this->pack->update(['content_quantity' => '5', 'content_unit_id' => $this->kg->id]);

    ($this->fillShelves)();

    // 104 consumed, 4 recorded waste: net 100 = expected. Nothing to flag -
    // the loss is declared, which is exactly what "explained" means.
    $run = ($this->confirm)(($this->draft)(['lines' => [
        ['product' => $this->oil, 'quantity' => '104', 'waste' => '4'],
        ['product' => $this->bottle, 'quantity' => '20'],
        ['product' => $this->cap, 'quantity' => '20'],
    ]]));

    expect($run->yield_check['checked'])->toBeTrue()
        ->and($run->yield_check['flagged'])->toBeFalse();
});

it('produces no flag and no refusal when the output declares no content', function () {
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)(['lines' => [
        ['product' => $this->oil, 'quantity' => '150'],
        ['product' => $this->bottle, 'quantity' => '20'],
        ['product' => $this->cap, 'quantity' => '20'],
    ]]));

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and($run->yield_check['checked'])->toBeFalse()
        ->and($run->yieldFlagged())->toBeFalse();
});

// Acceptance 9: the anchor rule.
it('still confirms and posts nothing when the wastage mapping is missing', function () {
    AccountMapping::where('key', 'inventory.production_wastage')->delete();

    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)(['oilWaste' => '2', 'expenseWaste' => true]));

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and((string) $run->waste_value)->toBe('360.0000')
        ->and(($this->runJournals)($run))->toHaveCount(0)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(0);
});

// Acceptance 11 (C-MFG-11): waste visible per line and per run, absorbed by default.
it('stores per-line and per-run waste values at FIFO cost without posting anything', function () {
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)(['lines' => [
        ['product' => $this->oil, 'quantity' => '100', 'waste' => '2'],
        ['product' => $this->bottle, 'quantity' => '20', 'waste' => '1'],
        ['product' => $this->cap, 'quantity' => '20'],
    ]]));

    $byProduct = $run->lines->keyBy('product_id');

    expect((string) $byProduct[$this->oil->id]->consumed_value)->toBe('18000.0000')
        ->and((string) $byProduct[$this->oil->id]->waste_value)->toBe('360.0000')
        ->and((string) $byProduct[$this->bottle->id]->waste_value)->toBe('15.0000')
        ->and((string) $byProduct[$this->cap->id]->waste_value)->toBe('0.0000')
        ->and((string) $run->waste_value)->toBe('375.0000')
        // Absorbed: the packs cost what the run cost, and no journal exists.
        ->and((string) $run->outputBatch->unit_cost)->toBe('917.0000')
        ->and(($this->runJournals)($run))->toHaveCount(0);
});

// Acceptance 12 (C-MFG-13): batch identity for finished goods.
it('stamps the output batch with manufactured date and MRP and leaves purchased batches without either', function () {
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)(['mrp' => '1250.00']));

    expect($run->outputBatch->manufactured_date->toDateString())->toBe('2026-08-17')
        ->and((string) $run->outputBatch->mrp)->toBe('1250.0000')
        ->and($run->outputBatch->batch_number)->toBe($run->run_number)
        ->and($run->outputBatch->expiry_date->toDateString())->toBe('2027-08-17');

    $purchased = StockBatch::where('product_id', $this->oil->id)->first();

    expect($purchased->manufactured_date)->toBeNull()
        ->and($purchased->mrp)->toBeNull();
});

// The rounding residue: a paisa the 4dp unit cost cannot carry is posted
// visibly rather than silently breaking the subledger against the ledger.
it('posts the unavoidable rounding residue of a large absorbed run to the rounding account', function () {
    ($this->stockIn)($this->oil, '1000', '100.0001');

    $run = ($this->confirm)(($this->draft)([
        'outputQuantity' => '3000',
        'lines' => [['product' => $this->oil, 'quantity' => '1000']],
    ]));

    // Consumed 100,000.10; unit cost 33.3334; batch value 100,000.20.
    expect((string) $run->consumed_value)->toBe('100000.1000')
        ->and((string) $run->outputBatch->unit_cost)->toBe('33.3334');

    // The ledger moves by exactly the 10 paisa the batch now over-carries.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(10)
        ->and(($this->balanceOn)('rounding_difference'))->toBe(-10)
        ->and(($this->runJournals)($run)->sole()->balance()->isZero())->toBeTrue();
});

// C-MFG-10 and the document rules.
it('refuses an output that is also an input', function () {
    expect(fn () => ($this->draft)(['lines' => [['product' => $this->pack, 'quantity' => '5']]]))
        ->toThrow(ValidationException::class);
});

it('refuses waste exceeding the consumed quantity', function () {
    expect(fn () => ($this->draft)(['lines' => [['product' => $this->oil, 'quantity' => '10', 'waste' => '11']]]))
        ->toThrow(ValidationException::class);
});

it('refuses to confirm twice', function () {
    ($this->fillShelves)();
    $run = ($this->confirm)(($this->draft)());

    expect(fn () => ($this->confirm)($run->fresh()))->toThrow(RuntimeException::class);
});

it('numbers runs sequentially within the fiscal year', function () {
    ($this->fillShelves)();

    $first = ($this->draft)();
    $second = ($this->draft)();

    expect(str_starts_with($first->run_number, 'PRN-'))->toBeTrue()
        ->and(str_ends_with($first->run_number, '-0001'))->toBeTrue()
        ->and((int) substr($second->run_number, -4))->toBe((int) substr($first->run_number, -4) + 1);
});

it('refuses a second confirm made from a stale copy of the draft', function () {
    // ⚠⚠ The double-click: both requests loaded the run as a draft. The second
    // must not consume the inputs and produce the output again.
    ($this->fillShelves)();
    $draft = ($this->draft)();
    $stale = $draft->fresh();

    ($this->confirm)($draft);

    expect(fn () => ($this->confirm)($stale))->toThrow(RuntimeException::class);

    expect(StockMovement::where('production_run_id', $draft->id)->where('direction', MovementDirection::In)->count())->toBe(1);
});

it('lets a run dated today use stock received earlier today', function () {
    // ⚠⚠ The screen sends a bare date. As midnight, a run dated today refused
    // every input received after 00:00 today.
    $stockInToday = fn (Product $p, string $qty, string $cost) => app(RecordStockIn::class)->handle(
        product: $p,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->user,
        occurredAt: '2026-08-17 08:30:00',
    );
    $stockInToday($this->oil, '200', '180.00');
    $stockInToday($this->bottle, '50', '15.00');
    $stockInToday($this->cap, '50', '2.00');

    $run = ($this->confirm)(($this->draft)(['occurredAt' => '2026-08-17']));

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and($run->occurred_at->toDateTimeString())->toBe('2026-08-17 10:00:00');
});

it('dates a run entered for an earlier day at the end of that day', function () {
    ($this->fillShelves)();   // received 16 Aug at 09:00

    $run = ($this->confirm)(($this->draft)(['occurredAt' => '2026-08-16']));

    expect($run->occurred_at->toDateTimeString())->toBe('2026-08-16 23:59:59')
        ->and((float) $run->outputBatch->current_quantity)->toBe(20.0);
});

// 17 Oct 2026: the third door stock comes in by keeps the goods-receipt rule.
it('refuses a run whose output tracks expiry when no expiry date is given', function () {
    $this->pack->update(['tracks_expiry' => true]);
    ($this->fillShelves)();

    $draftWithout = fn (?string $expiry) => app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack->fresh(),
        outputQuantity: '20',
        lines: [['product' => $this->oil, 'quantity' => '100']],
        createdBy: $this->user,
        outputExpiryDate: $expiry,
    );

    foreach ([null, '', '  '] as $blank) {
        try {
            $draftWithout($blank);
            $this->fail('A run of an expiry-tracked output was drafted without an expiry date.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('output_expiry_date')
                ->and($e->errors()['output_expiry_date'][0])->toContain('tracks expiry');
        }
    }

    expect(ProductionRun::count())->toBe(0)
        ->and($draftWithout('2027-08-17')->output_expiry_date->toDateString())->toBe('2027-08-17');
});

it('does not ask for an expiry date when the output does not track expiry', function () {
    ($this->fillShelves)();

    $run = app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack,
        outputQuantity: '20',
        lines: [['product' => $this->oil, 'quantity' => '100']],
        createdBy: $this->user,
    );

    expect($run->output_expiry_date)->toBeNull();
});

// 17 Oct 2026: g and kg (and mL and L) are the same dimension; the yield check
// reads one as the other by the metric factor and never crosses dimensions.
it('computes the yield for a 500 g pack filled from bulk stocked in kg', function () {
    $gram = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);
    $this->pack->update(['content_quantity' => '500', 'content_unit_id' => $gram->id]);
    ($this->fillShelves)();

    // 20 packs x 500 g = 10,000 g expected; 10 kg consumed = 10,000 g.
    $run = ($this->confirm)(($this->draft)(['lines' => [
        ['product' => $this->oil, 'quantity' => '10'],
        ['product' => $this->bottle, 'quantity' => '20'],
    ]]));

    expect($run->yield_check['checked'])->toBeTrue()
        ->and($run->yield_check['content_unit'])->toBe('g')
        ->and($run->yield_check['expected_content'])->toBe('10000.0000')
        ->and($run->yield_check['net_consumed'])->toBe('10000.0000')
        ->and($run->yield_check['converted_from'])->toBe(['kg'])
        ->and((float) $run->yield_check['deviation_percent'])->toBe(0.0)
        ->and($run->yield_check['flagged'])->toBeFalse();
});

it('flags a drifting yield across g and kg, net of waste', function () {
    $gram = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'gm']);
    $this->pack->update(['content_quantity' => '500', 'content_unit_id' => $gram->id]);
    ($this->fillShelves)();

    // 11.5 kg consumed, 0.5 kg recorded waste: net 11 kg against 10 kg = +10%.
    $run = ($this->confirm)(($this->draft)(['lines' => [
        ['product' => $this->oil, 'quantity' => '11.5', 'waste' => '0.5'],
    ]]));

    expect($run->yield_check['checked'])->toBeTrue()
        ->and($run->yield_check['net_consumed'])->toBe('11000.0000')
        ->and((float) $run->yield_check['deviation_percent'])->toBe(10.0)
        ->and($run->yield_check['flagged'])->toBeTrue();
});

it('reads a 500 mL bottle against bulk stocked in L', function () {
    $millilitre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Millilitre', 'abbreviation' => 'mL']);
    $drum = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->litre->id,
        'sku' => 'OIL-DRUM', 'name' => 'Oil drum', 'type' => ProductType::RawMaterial,
    ]);
    ($this->stockIn)($drum, '100', '200.00');
    $this->pack->update(['content_quantity' => '500', 'content_unit_id' => $millilitre->id]);

    $run = ($this->confirm)(($this->draft)(['lines' => [['product' => $drum, 'quantity' => '10']]]));

    expect($run->yield_check['checked'])->toBeTrue()
        ->and($run->yield_check['expected_content'])->toBe('10000.0000')
        ->and($run->yield_check['flagged'])->toBeFalse();
});

it('never compares kg with L', function () {
    $this->pack->update(['content_quantity' => '5', 'content_unit_id' => $this->litre->id]);
    ($this->fillShelves)();

    $run = ($this->confirm)(($this->draft)());

    expect($run->yield_check['checked'])->toBeFalse()
        ->and($run->yield_check['reason'])->toBe('no_input_in_content_unit');
});
