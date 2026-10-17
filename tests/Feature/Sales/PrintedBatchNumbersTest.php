<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Owner, 17 Oct 2026: the batch number and its expiry print under each item
 * on the challan and on the invoice. A recall starts from the paper the
 * customer holds, so the batch that LEFT must be on it, read from the stock
 * movements the line produced, never from the batch FEFO would pick today.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Batch Print Co', 'slug' => 'batch-print-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $product = fn (string $sku, string $name) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'name' => $name,
        'type' => ProductType::FinishedGood,
    ]);
    $this->ghee = $product('LLD-GHE-PURE-500G', 'Pure Ghee 500g');
    $this->honey = $product('LLD-HON-MAN-500G', 'Mangrove Gold Honey 500g');
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001',
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    Carbon::setTestNow('2026-09-01 10:00:00');

    // Two numbered batches of ghee; honey with no batch number at all.
    app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '2', '400', $this->owner, batchNumber: 'GHE-2608-A', expiryDate: '2026-12-31', occurredAt: '2026-08-20 09:00:00');
    app(RecordStockIn::class)->handle($this->ghee, $this->warehouse, '10', '410', $this->owner, batchNumber: 'GHE-2608-B', expiryDate: '2027-06-30', occurredAt: '2026-08-28 09:00:00');
    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '10', '250', $this->owner, occurredAt: '2026-08-28 09:00:00');

    $this->delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [
            ['product' => $this->ghee, 'quantity' => '3'],
            ['product' => $this->honey, 'quantity' => '1'],
        ],
        createdBy: $this->owner,
    );

    $lines = $this->delivery->lines()->orderBy('id')->get();
    $this->invoice = app(RecordSalesInvoice::class)->handle(
        customer: $this->customer,
        lines: [
            ['deliveryLine' => $lines[0], 'quantity' => '3', 'unitPrice' => '690'],
            ['deliveryLine' => $lines[1], 'quantity' => '1', 'unitPrice' => '500'],
        ],
        createdBy: $this->owner,
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

$gheeBatches = [
    ['batch_number' => 'GHE-2608-A', 'expiry_date' => '31 Dec 2026', 'quantity' => 2.0],
    ['batch_number' => 'GHE-2608-B', 'expiry_date' => '30 Jun 2027', 'quantity' => 1.0],
];

$shape = fn (array $batches) => collect($batches)
    ->map(fn ($b) => ['batch_number' => $b['batch_number'], 'expiry_date' => $b['expiry_date'], 'quantity' => (float) $b['quantity']])
    ->all();

it('prints the batch number and expiry each challan line took', function () use ($gheeBatches, $shape) {
    $props = ($this->asOwner)()->get(route('sales.deliveries.print', $this->delivery))
        ->assertOk()
        ->viewData('page')['props'];

    $lines = collect($props['delivery']['lines'])->sortBy('id')->values();

    // One line across two batches: both, oldest expiry first, with how many each.
    expect($shape($lines[0]['batches']))->toBe($gheeBatches)
        // A batch with no number has nothing to print.
        ->and($lines[1]['batches'])->toBe([]);
});

it('prints the same batches on the invoice, through the challan line it bills', function () use ($gheeBatches, $shape) {
    $props = ($this->asOwner)()->get(route('sales.invoices.print', $this->invoice))
        ->assertOk()
        ->viewData('page')['props'];

    $lines = collect($props['invoice']['lines'])->sortBy('id')->values();

    expect($shape($lines[0]['batches']))->toBe($gheeBatches)
        ->and($lines[1]['batches'])->toBe([]);
});
