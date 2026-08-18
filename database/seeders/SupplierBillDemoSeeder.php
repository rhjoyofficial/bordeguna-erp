<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⚠⚠ LOCAL DEMO ONLY. Builds just enough to drive the supplier bill flow in a
 * browser: one business, one manager, an approved purchase order and a goods
 * receipt waiting to be billed.
 *
 * ⛔ NEVER run against production. It creates a user with a known password.
 */
class SupplierBillDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ⚠⚠ It creates a user with a KNOWN password, so it refuses to run
        // anywhere but a developer machine. A guard rather than a comment,
        // because a comment does not stop a tired hand at midnight.
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'SupplierBillDemoSeeder is for local use only. It creates a user with a known password.'
            );
        }

        $business = Business::create([
            'name' => 'Bill Demo', 'slug' => 'bill-demo', 'fiscal_year_start_month' => 7,
        ]);

        app(BusinessContext::class)->set($business->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'business_id' => $business->id]);
        }

        (new ChartOfAccountsSeeder)->run();
        (new InventoryAccountMappingSeeder)->run();
        (new PurchasingPermissionsSeeder)->run();

        app(BusinessContext::class)->set($business->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

        $user = User::factory()->create([
            'name' => 'Bill Demo Manager',
            'email' => 'billdemo@example.test',
            'password' => bcrypt('demo-password-not-real'),
        ]);
        $user->businesses()->attach($business->id);
        setPermissionsTeamId($business->id);
        $user->assignRole('Manager');

        $unit = UnitOfMeasure::create([
            'business_id' => $business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg',
        ]);
        $warehouse = Warehouse::create([
            'business_id' => $business->id, 'name' => 'Mohammadpur', 'code' => 'MOH', 'is_default' => true,
        ]);
        $product = Product::create([
            'business_id' => $business->id,
            'unit_of_measure_id' => $unit->id,
            'sku' => 'OIL-BULK',
            'name' => 'Mustard oil bulk',
            'type' => ProductType::RawMaterial,
        ]);
        $supplier = Supplier::create([
            'business_id' => $business->id,
            'name' => 'Razzak Oil Mill',
            'code' => 'SUP-RZK',
            'payment_terms' => '30 days',
        ]);

        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $supplier,
            warehouse: $warehouse,
            lines: [['product' => $product, 'quantity' => '100', 'unit_cost' => '320.00']],
            createdBy: $user,
        );
        $order->approvalRequest->approve($user);
        $order = $order->fresh(['lines', 'approvalRequest']);

        app(RecordGoodsReceipt::class)->handle($order, [
            ['line' => $order->lines->first(), 'quantity' => '100'],
        ], $user);

        $this->command?->info("Demo ready: business {$business->id}, order {$order->po_number}, user {$user->email}");
    }
}
