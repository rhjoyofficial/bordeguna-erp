<?php

namespace App\Modules\Inventory\database\seeders;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a default warehouse, a standard unit set, and a handful of realistic
 * demo products (with opening stock, via the real RecordStockIn action) per
 * business, so the UI has real-looking data immediately.
 */
class InventoryDemoDataSeeder extends Seeder
{
    private const UNITS = [
        ['name' => 'Pieces', 'abbreviation' => 'pcs'],
        ['name' => 'Kilogram', 'abbreviation' => 'kg'],
        ['name' => 'Gram', 'abbreviation' => 'g'],
        ['name' => 'Litre', 'abbreviation' => 'L'],
        ['name' => 'Millilitre', 'abbreviation' => 'mL'],
        ['name' => 'Box', 'abbreviation' => 'box'],
    ];

    private const CATALOGS = [
        'laladia' => [
            'categories' => ['Raw Materials', 'Finished Goods'],
            'products' => [
                ['name' => 'Basmati Rice', 'sku' => 'LLD-RICE-01', 'category' => 'Raw Materials', 'unit' => 'kg', 'type' => ProductType::RawMaterial, 'threshold' => 20, 'opening_qty' => '80', 'unit_cost' => '120'],
                ['name' => 'Cooking Oil', 'sku' => 'LLD-OIL-01', 'category' => 'Raw Materials', 'unit' => 'L', 'type' => ProductType::RawMaterial, 'threshold' => 10, 'opening_qty' => '40', 'unit_cost' => '190'],
                ['name' => 'Chicken Breast', 'sku' => 'LLD-CHKN-01', 'category' => 'Raw Materials', 'unit' => 'kg', 'type' => ProductType::RawMaterial, 'threshold' => 15, 'opening_qty' => '25', 'unit_cost' => '260'],
                ['name' => 'Chicken Biryani (Serving)', 'sku' => 'LLD-BRYN-01', 'category' => 'Finished Goods', 'unit' => 'pcs', 'type' => ProductType::FinishedGood, 'threshold' => 5, 'opening_qty' => '30', 'unit_cost' => '150'],
            ],
        ],
        'bionic' => [
            'categories' => ['Raw Materials', 'Finished Goods'],
            'products' => [
                ['name' => 'Potting Soil', 'sku' => 'BNC-SOIL-01', 'category' => 'Raw Materials', 'unit' => 'kg', 'type' => ProductType::RawMaterial, 'threshold' => 25, 'opening_qty' => '100', 'unit_cost' => '35'],
                ['name' => 'Tomato Seeds', 'sku' => 'BNC-SEED-01', 'category' => 'Raw Materials', 'unit' => 'box', 'type' => ProductType::RawMaterial, 'threshold' => 5, 'opening_qty' => '20', 'unit_cost' => '80'],
                ['name' => 'Organic Fertilizer', 'sku' => 'BNC-FERT-01', 'category' => 'Raw Materials', 'unit' => 'kg', 'type' => ProductType::RawMaterial, 'threshold' => 10, 'opening_qty' => '15', 'unit_cost' => '60'],
                ['name' => 'Herb Garden Starter Kit', 'sku' => 'BNC-KIT-01', 'category' => 'Finished Goods', 'unit' => 'pcs', 'type' => ProductType::FinishedGood, 'threshold' => 5, 'opening_qty' => '18', 'unit_cost' => '450'],
            ],
        ],
        'azmion' => [
            'categories' => ['Hardware', 'Services'],
            'products' => [
                ['name' => 'Demo Router Unit', 'sku' => 'AZM-RTR-01', 'category' => 'Hardware', 'unit' => 'pcs', 'type' => ProductType::FinishedGood, 'threshold' => 2, 'opening_qty' => '6', 'unit_cost' => '3200'],
                ['name' => 'Web Development Package', 'sku' => 'AZM-SVC-01', 'category' => 'Services', 'unit' => 'pcs', 'type' => ProductType::Service, 'threshold' => null, 'opening_qty' => null, 'unit_cost' => null],
                ['name' => 'IT Support Retainer', 'sku' => 'AZM-SVC-02', 'category' => 'Services', 'unit' => 'pcs', 'type' => ProductType::Service, 'threshold' => null, 'opening_qty' => null, 'unit_cost' => null],
            ],
        ],
    ];

    public function run(RecordStockIn $recordStockIn): void
    {
        $registrar = app(PermissionRegistrar::class);
        $context = app(BusinessContext::class);

        foreach (self::CATALOGS as $slug => $catalog) {
            $business = Business::where('slug', $slug)->firstOrFail();
            $owner = User::where('email', "owner@{$slug}.bordeguna-erp.test")->firstOrFail();

            $context->set($business->id);
            $registrar->setPermissionsTeamId($business->id);

            $warehouse = Warehouse::firstOrCreate(
                ['business_id' => $business->id, 'code' => 'MAIN'],
                ['name' => 'Main Warehouse', 'is_default' => true, 'is_active' => true]
            );

            $units = collect(self::UNITS)->mapWithKeys(function ($unit) use ($business) {
                $model = UnitOfMeasure::firstOrCreate(
                    ['business_id' => $business->id, 'abbreviation' => $unit['abbreviation']],
                    ['name' => $unit['name']]
                );

                return [$unit['abbreviation'] => $model];
            });

            $categories = collect($catalog['categories'])->mapWithKeys(function ($name) use ($business) {
                $model = Category::firstOrCreate(
                    ['business_id' => $business->id, 'slug' => Str::slug($name)],
                    ['name' => $name]
                );

                return [$name => $model];
            });

            foreach ($catalog['products'] as $item) {
                $product = Product::firstOrCreate(
                    ['business_id' => $business->id, 'sku' => $item['sku']],
                    [
                        'category_id' => $categories[$item['category']]->id,
                        'unit_of_measure_id' => $units[$item['unit']]->id,
                        'name' => $item['name'],
                        'type' => $item['type'],
                        'low_stock_threshold' => $item['threshold'],
                        'is_active' => true,
                    ]
                );

                // wasRecentlyCreated guards against double-stocking if this seeder
                // ever runs again without a migrate:fresh in between.
                if ($product->wasRecentlyCreated && $item['type']->isStocked() && $item['opening_qty'] !== null) {
                    $recordStockIn->handle(
                        product: $product,
                        warehouse: $warehouse,
                        quantity: $item['opening_qty'],
                        unitCost: $item['unit_cost'],
                        performedBy: $owner,
                        note: 'Opening balance (demo seed data)',
                    );
                }
            }
        }

        $context->forget();
        $registrar->setPermissionsTeamId(null);
    }
}
