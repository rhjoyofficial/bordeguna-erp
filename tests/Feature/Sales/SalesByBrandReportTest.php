<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Reporting\Definitions\SalesByBrandReport;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales by brand: the calculation Joy actually asked for when the group
 * layer interview revealed the "companies" are brands. Invoiced sales
 * grouped by the product's brand; unbranded products land in "No brand"
 * so the totals still foot; costs are deliberately not split.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Brand Co', 'slug' => 'brand-co']);
    $this->user = \App\Models\User::factory()->create();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $bng = Brand::create(['business_id' => $this->business->id, 'code' => 'BNG', 'name' => 'Bionic Garden', 'slug' => 'bionic-garden']);

    $make = fn (string $sku, ?int $brandId) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => $sku, 'name' => $sku, 'brand_id' => $brandId,
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    $honey = $make('HONEY', $lld->id);
    $shutki = $make('SHUTKI', $lld->id);
    $plant = $make('PLANT', $bng->id);
    $plain = $make('PLAIN', null);

    $invoice = DB::table('sales_invoices')->insertGetId([
        'business_id' => $this->business->id,
        'customer_id' => DB::table('customers')->insertGetId([
            'business_id' => $this->business->id, 'code' => 'CUS-1', 'name' => 'Rahim', 'type' => 'retail',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'invoice_number' => 'INV-1', 'invoice_date' => '2026-09-05',
        'subtotal' => 5000, 'invoice_discount' => 0, 'tax_total' => 0, 'total' => 5000,
        'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([[$honey, 2, 1700], [$shutki, 1, 2000], [$plant, 3, 900], [$plain, 1, 400]] as [$product, $qty, $net]) {
        DB::table('sales_invoice_lines')->insert([
            'business_id' => $this->business->id, 'sales_invoice_id' => $invoice,
            'product_id' => $product->id, 'quantity' => $qty,
            'unit_price' => $net / $qty, 'line_discount' => 0, 'line_net' => $net,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('groups invoiced sales by brand, with unbranded rows still footing', function () {
    $report = new SalesByBrandReport;
    $rows = $report->rows(new ReportParameters([]))->collect();

    $byBrand = $rows->keyBy('brand');

    expect($byBrand['LaLaDia']['revenue'])->toBe(3700.0)
        ->and($byBrand['LaLaDia']['quantity'])->toBe(3.0)
        ->and($byBrand['LaLaDia']['products_sold'])->toBe(2)
        ->and($byBrand['Bionic Garden']['revenue'])->toBe(900.0)
        ->and($byBrand['No brand']['revenue'])->toBe(400.0)
        // The brand totals foot to total sales - no bucket hides rows.
        ->and($rows->sum('revenue'))->toBe(5000.0);
});

it('is registered in the reporting engine under the Sales group', function () {
    $definition = app(ReportRegistry::class)->all()
        ->first(fn ($d) => $d->key() === 'sales_by_brand');

    expect($definition)->not->toBeNull()
        ->and($definition->group())->toBe('Sales')
        ->and($definition->permission())->toBe('sales.reports.view');
});
