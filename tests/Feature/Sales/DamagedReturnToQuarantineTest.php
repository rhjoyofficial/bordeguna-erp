<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\MoveStockBackToSale;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B7 (24 Oct 2026; Q7 = A): "damaged returns go to a
 * Not-for-sale (quarantine) warehouse; whatever is reusable afterwards is
 * moved back to sellable stock (with a reason), the rest written off."
 *
 * The damaged share is restored onto the batch the sale took it from (so its
 * cost is exactly what left) and moved, same batch, into quarantine: stock
 * still, nothing written off yet.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Returns Co', 'slug' => 'returns-co-b7']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR', 'is_default' => true]);
    $this->quarantine = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Damaged / Quarantine', 'code' => 'QRN', 'not_for_sale' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Dealer]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-10-24 10:00:00');

    app(RecordOpeningStock::class)->handle($this->warehouse, [[
        'product' => $this->product, 'quantity' => '20', 'unitCost' => '500',
    ]], $this->user, asAt: '2026-10-01 09:00:00');

    $this->delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10']],
        createdBy: $this->user,
    );

    $this->returnGoods = fn (string $toStock, string $toQuarantine, string $writeOff = '0', ?Warehouse $into = null) => app(RecordSalesReturn::class)->handle(
        delivery: $this->delivery,
        lines: [[
            'deliveryLine' => $this->delivery->lines->first(),
            'quantityToStock' => $toStock,
            'quantityWrittenOff' => $writeOff,
            'quantityToQuarantine' => $toQuarantine,
        ]],
        createdBy: $this->user,
        quarantine: $into,
    );

    $this->balance = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('sends the damaged share to quarantine as stock at the cost it left at, nothing written off', function () {
    $return = ($this->returnGoods)('2', '3');

    $source = StockBatch::where('warehouse_id', $this->warehouse->id)->sole();
    $held = StockBatch::where('warehouse_id', $this->quarantine->id)->sole();
    $line = $return->lines->sole();

    expect($return->quarantine_warehouse_id)->toBe($this->quarantine->id)
        ->and((float) $line->quantity_to_stock)->toBe(2.0)
        ->and((float) $line->quantity_to_quarantine)->toBe(3.0)
        ->and((float) $line->quantity)->toBe(5.0)
        // 20 in, 10 out, 5 back, 3 of those on to quarantine.
        ->and((float) $source->current_quantity)->toBe(12.0)
        ->and((float) $held->current_quantity)->toBe(3.0)
        ->and($held->batch_number)->toBe($source->batch_number)
        ->and((float) $held->unit_cost)->toBe(500.0)
        ->and(StockTransfer::sole()->note)->toBe("Damaged, returned on {$return->return_number}")
        // All five come back into stock at 500: nothing on a loss head yet.
        ->and((float) $line->cost_restored)->toBe(2500.0)
        ->and((float) $line->cost_written_off)->toBe(0.0)
        ->and(($this->balance)('inventory_loss_damaged_returns'))->toBe(0)
        ->and(SubledgerReconciliation::reconcileAllBusinesses()['problems'])->toBeEmpty();

    // Quarantine stock cannot be sold...
    expect(fn () => app(RecordStockOut::class)->handle($this->product, $this->quarantine, '1', $this->user, reason: MovementReason::Sale))
        ->toThrow(DomainException::class);

    // ...but what is reusable moves back to the shop, same batch and cost.
    app(MoveStockBackToSale::class)->handle($held, $this->warehouse, '1', 'Label only was torn', $this->user);

    expect((float) $held->fresh()->current_quantity)->toBe(2.0)
        ->and((float) StockBatch::where('warehouse_id', $this->warehouse->id)->sum('current_quantity'))->toBe(13.0)
        ->and(SubledgerReconciliation::reconcileAllBusinesses()['problems'])->toBeEmpty();
});

it('keeps writing off at once when asked, on the Damaged returns head', function () {
    $return = ($this->returnGoods)('0', '0', '2');

    expect(StockTransfer::count())->toBe(0)
        ->and((float) $return->lines->sole()->cost_written_off)->toBe(1000.0)
        ->and(($this->balance)('inventory_loss_damaged_returns'))->toBe(100000);
});

it('refuses quarantine when the business has none, or a warehouse that sells', function () {
    expect(fn () => ($this->returnGoods)('0', '1', '0', $this->warehouse))
        ->toThrow(ValidationException::class, 'Savar is not an active Not for sale warehouse.');

    $this->quarantine->update(['not_for_sale' => false]);

    expect(fn () => ($this->returnGoods)('0', '1'))
        ->toThrow(ValidationException::class, 'This business has no Not for sale warehouse.');

    expect(SalesReturn::count())->toBe(0)
        ->and(Journal::where('source_type', (new SalesReturn)->getMorphClass())->count())->toBe(0);
});

it('asks which one when there are two', function () {
    Warehouse::create(['business_id' => $this->business->id, 'name' => 'Spoilt room', 'code' => 'SPL', 'not_for_sale' => true]);

    expect(fn () => ($this->returnGoods)('0', '1'))
        ->toThrow(ValidationException::class, 'Choose which Not for sale warehouse the damaged goods go to.');

    ($this->returnGoods)('0', '1', '0', $this->quarantine);
    expect((float) StockBatch::where('warehouse_id', $this->quarantine->id)->sum('current_quantity'))->toBe(1.0);
});
