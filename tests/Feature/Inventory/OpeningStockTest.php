<?php

use App\Models\User;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * C-FLOW-5 step 2: what the warehouse already held on the day the books start.
 *
 * ⚠⚠ This is the document that makes deleting the generic "receive stock"
 * screen possible. Opening inventory is not a purchase - no supplier, no bill,
 * no liability - and not an adjustment, because nothing is being corrected.
 *
 * See INVENTORY-ENTRY-POINTS-DESIGN.md §3.3.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Opening Co', 'slug' => 'opening-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->other = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE',
        'name' => 'Pure Ghee 1kg',
        'type' => ProductType::FinishedGood,
    ]);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-08-04 10:00:00');

    $this->record = fn (array $lines, ?Warehouse $warehouse = null) => app(RecordOpeningStock::class)->handle(
        warehouse: $warehouse ?? $this->warehouse,
        lines: $lines,
        createdBy: $this->user,
        asAt: '2026-07-31',
    );

    $this->balanceOf = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('creates the batches the business already held', function () {
    ($this->record)([
        ['product' => $this->product, 'quantity' => '12', 'unitCost' => '480'],
        ['product' => $this->other, 'quantity' => '8', 'unitCost' => '615.50'],
    ]);

    expect($this->product->fresh()->currentStockQuantity())->toBe('12')
        ->and($this->other->fresh()->currentStockQuantity())->toBe('8');
});

it('keeps the batch number and expiry, because FIFO will consume them', function () {
    ($this->record)([[
        'product' => $this->product,
        'quantity' => '10',
        'unitCost' => '500',
        'batchNumber' => 'B-2026-07',
        'expiryDate' => '2027-01-31',
    ]]);

    $batch = $this->product->fresh()->batches()->first();

    expect($batch->batch_number)->toBe('B-2026-07')
        ->and($batch->expiry_date->toDateString())->toBe('2027-01-31');
});

describe('the ledger', function () {
    // 12 x 480 = 5760, 8 x 615.50 = 4924, total 10684.
    it('debits Stock on Hand and credits Opening Balance Equity', function () {
        ($this->record)([
            ['product' => $this->product, 'quantity' => '12', 'unitCost' => '480'],
            ['product' => $this->other, 'quantity' => '8', 'unitCost' => '615.50'],
        ]);

        expect(($this->balanceOf)('stock_on_hand'))->toBe(1068400)
            ->and(($this->balanceOf)('opening_balance_equity'))->toBe(-1068400)
            ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
    });

    // ⚠⚠ The type is what lets a report tell the opening photograph apart from a
    // year of trading, and it is what makes Opening Balance Equity going to zero
    // a meaningful check.
    it('posts as an Opening Balance, not an ordinary journal', function () {
        $opening = ($this->record)([['product' => $this->product, 'quantity' => '5', 'unitCost' => '100']]);

        $journal = Journal::where('source_type', $opening->getMorphClass())
            ->where('source_id', $opening->id)->first();

        expect($journal)->not->toBeNull()
            ->and($journal->journal_type)->toBe(JournalType::OpeningBalance)
            ->and(Carbon::parse($journal->journal_date)->toDateString())->toBe('2026-07-31');
    });

    // ⚠ Valued from the MOVEMENTS, never the line's own figures - the same rule
    // that values a goods receipt from what actually entered stock.
    it('values the journal from the movements it created', function () {
        $opening = ($this->record)([['product' => $this->product, 'quantity' => '3', 'unitCost' => '333.3333']]);

        expect($opening->totalValue()->toTaka())->toBe('1000.00')
            ->and(($this->balanceOf)('stock_on_hand'))->toBe(100000);
    });

    // ⚠⚠ The standing rule: an accounting feature must never stop an operational
    // process. A business with no ledger must still be able to say what it holds.
    it('still records the stock when the ledger is not configured', function () {
        AccountMapping::where('key', 'inventory.stock_on_hand')->delete();

        $opening = ($this->record)([['product' => $this->product, 'quantity' => '7', 'unitCost' => '100']]);

        expect($this->product->fresh()->currentStockQuantity())->toBe('7')
            ->and(Journal::where('source_id', $opening->id)
                ->where('source_type', $opening->getMorphClass())->count())->toBe(0);
    });
});

// ⚠⚠ Entered twice, the inventory doubles and NO report disagrees - the trial
// balance still balances, because the second entry credits equity just as
// correctly as the first.
it('refuses a second opening stock for the same warehouse', function () {
    ($this->record)([['product' => $this->product, 'quantity' => '5', 'unitCost' => '100']]);
    ($this->record)([['product' => $this->product, 'quantity' => '5', 'unitCost' => '100']]);
})->throws(ValidationException::class, 'already been recorded');

it('allows opening stock for a different warehouse', function () {
    $second = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Uttar Adabor', 'code' => 'UA']);

    ($this->record)([['product' => $this->product, 'quantity' => '5', 'unitCost' => '100']]);
    ($this->record)([['product' => $this->product, 'quantity' => '4', 'unitCost' => '100']], $second);

    expect(OpeningStock::count())->toBe(2)
        ->and($this->product->fresh()->currentStockQuantity())->toBe('9');
});

it('refuses a service, which cannot hold stock', function () {
    $service = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->product->unit_of_measure_id,
        'sku' => 'SKU-FEE',
        'name' => 'Consulting',
        'type' => ProductType::Service,
    ]);

    ($this->record)([['product' => $service, 'quantity' => '1', 'unitCost' => '100']]);
})->throws(ValidationException::class, 'cannot hold stock');

it('refuses a negative unit cost', function () {
    ($this->record)([['product' => $this->product, 'quantity' => '1', 'unitCost' => '-5']]);
})->throws(ValidationException::class, 'cannot be negative');

it('refuses an empty document', function () {
    ($this->record)([['product' => $this->product, 'quantity' => '0', 'unitCost' => '100']]);
})->throws(ValidationException::class, 'at least one line');

// ⚠ All or nothing. A refused document must leave no stock and no journal.
it('leaves nothing behind when a line is rejected', function () {
    try {
        ($this->record)([
            ['product' => $this->product, 'quantity' => '5', 'unitCost' => '100'],
            ['product' => $this->other, 'quantity' => '5', 'unitCost' => '-1'],
        ]);
    } catch (ValidationException) {
        // expected
    }

    expect(OpeningStock::count())->toBe(0)
        ->and($this->product->fresh()->currentStockQuantity())->toBe('0')
        ->and(JournalLine::count())->toBe(0);
});
