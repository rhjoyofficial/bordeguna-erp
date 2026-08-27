<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Assets\database\seeders\AssetAccountMappingSeeder;
use App\Modules\Assets\database\seeders\AssetsPermissionsSeeder;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * A throwaway business that mirrors Bor de Guna's shape, for exercising the
 * complete operational spine with data that can be deleted afterwards.
 *
 * ⚠⚠ WHY A SEPARATE BUSINESS RATHER THAN A `DUMMY-` PREFIX INSIDE BOR DE GUNA.
 * A dummy sale does not create a dummy row - it creates a posted JOURNAL, FIFO
 * cost layers, and `restrictOnDelete` links from invoices, deliveries and
 * receipts. "Delete the dummy data afterwards" would therefore mean deleting
 * accounting entries from the real company's books, which is precisely what the
 * immutable ledger and the period locks exist to prevent. That is not a cleanup
 * script, it is a hole in the accounting controls.
 *
 * The ERP already has a hard isolation boundary that is enforced everywhere and
 * tested to fail closed: `business_id` + `BusinessScope`. Dummy data lives
 * behind it. Design note: DUMMY-DATA-DESIGN.md.
 *
 * ⛔ BOR DE GUNA RECEIVES NO DUMMY TRANSACTIONS OF ANY KIND.
 */
class SandboxSeeder extends Seeder
{
    /** ⚠ The teardown command refuses to delete anything but this slug. */
    public const SLUG = 'sandbox';

    public function run(): void
    {
        $business = Business::withoutGlobalScopes()->where('slug', self::SLUG)->first();

        if ($business !== null) {
            $this->command?->warn('Sandbox already exists; nothing seeded.');

            return;
        }

        $business = Business::create([
            'name' => 'DUMMY Sandbox',
            'slug' => self::SLUG,
            'currency' => 'BDT',
            'fiscal_year_start_month' => 7,
        ]);

        TenantScope::enter($business->id);

        try {
            $this->seedRolesAndPermissions($business);
            $this->seedAccounting();
            $this->seedInventoryMasters($business);
        } finally {
            TenantScope::leave();
        }

        $this->command?->info("Sandbox business #{$business->id} seeded.");
    }

    private function seedRolesAndPermissions(Business $business): void
    {
        foreach (['Owner', 'Manager', 'Staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'business_id' => $business->id]);
        }

        // ⚠ Every module's permission seeder, because permission behaviour is
        // part of what the end-to-end cycle is meant to test. They are additive
        // (`givePermissionTo`), so order does not matter.
        foreach ([
            InventoryPermissionsSeeder::class,
            PurchasingPermissionsSeeder::class,
            SalesPermissionsSeeder::class,
            AccountingPermissionsSeeder::class,
            CrmPermissionsSeeder::class,
            AssetsPermissionsSeeder::class,
        ] as $seeder) {
            (new $seeder)->run();
        }

        TenantScope::enter($business->id);
    }

    private function seedAccounting(): void
    {
        // The real chart and the real mappings. The postings this cycle produces
        // must be the ones production would produce, or the exercise proves
        // nothing.
        (new ChartOfAccountsSeeder)->run();
        (new InventoryAccountMappingSeeder)->run();
        (new SalesAccountMappingSeeder)->run();
        // ⚠⚠ Without this, depreciation writes its charge rows and posts
        // NOTHING - silently, by design, because the register is useful before
        // the ledger is configured. The screen now warns about it; the sandbox
        // seeds it so the cycle exercises the posting rather than the silence.
        (new AssetAccountMappingSeeder)->run();
        (new WalkInCustomerSeeder)->run();
    }

    private function seedInventoryMasters(Business $business): void
    {
        // ⚠ Bor de Guna's real warehouse codes and default rule: the default is
        // the primary SALES location, not the largest store.
        $adabor = Warehouse::create([
            'business_id' => $business->id, 'name' => 'DUMMY Uttar Adabor', 'code' => 'ADABOR', 'is_default' => true,
        ]);
        Warehouse::create(['business_id' => $business->id, 'name' => 'DUMMY Mohammadpur', 'code' => 'MDPUR']);
        Warehouse::create(['business_id' => $business->id, 'name' => 'DUMMY Savar', 'code' => 'SAVAR']);

        // ⚠⚠ A money account with a NULL account_id is accepted at creation and
        // only fails when somebody tries to PAY A SUPPLIER - see the findings in
        // DUMMY-DATA-DESIGN. The sandbox asserts here rather than discovering it
        // three steps later, which is exactly what the real setup screen should
        // do and does not.
        $accountId = function (string $slug): int {
            $account = Account::bySlug($slug);

            if ($account === null) {
                throw new \RuntimeException("Sandbox: no chart account with slug [{$slug}].");
            }

            return $account->id;
        };

        MoneyAccount::create([
            'business_id' => $business->id, 'name' => 'DUMMY BRAC Bank', 'type' => 'bank',
            'account_id' => $accountId('cash_at_bank'), 'bank_name' => 'BRAC', 'currency' => 'BDT',
        ]);
        MoneyAccount::create([
            'business_id' => $business->id, 'name' => 'DUMMY Cash in Hand', 'type' => 'cash',
            'account_id' => $accountId('cash_in_hand'), 'currency' => 'BDT',
        ]);

        // Both brands, so the brand dimension is exercised.
        $lld = Brand::create(['business_id' => $business->id, 'code' => 'LLD', 'name' => 'DUMMY LaLaDia', 'slug' => 'dummy-laladia']);
        $bnc = Brand::create(['business_id' => $business->id, 'code' => 'BNC', 'name' => 'DUMMY Bionic-Garden', 'slug' => 'dummy-bionic']);

        $piece = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
        $kg = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);

        $honey = Category::create(['business_id' => $business->id, 'name' => 'Honey', 'slug' => 'honey']);
        $dryFish = Category::create(['business_id' => $business->id, 'name' => 'Dry Fish', 'slug' => 'dry-fish']);

        // ⚠⚠ BOTH UNITS, DELIBERATELY. The by-weight path shipped on 9 August and
        // has never run purchase-to-sale outside unit tests. A Piece product
        // alone would prove only what was already proven.
        Product::create([
            'business_id' => $business->id, 'category_id' => $honey->id, 'brand_id' => $lld->id,
            'unit_of_measure_id' => $piece->id, 'sku' => 'DUMMY-HON-500G',
            'name' => 'DUMMY Mangrove Gold Honey 500gm', 'type' => ProductType::FinishedGood,
        ]);
        Product::create([
            'business_id' => $business->id, 'category_id' => $dryFish->id, 'brand_id' => $lld->id,
            'unit_of_measure_id' => $kg->id, 'sku' => 'DUMMY-SHUTKI-LOITTA',
            'name' => 'DUMMY Loitta Shutki', 'type' => ProductType::FinishedGood,
        ]);
        Product::create([
            'business_id' => $business->id, 'category_id' => $honey->id, 'brand_id' => $bnc->id,
            'unit_of_measure_id' => $piece->id, 'sku' => 'DUMMY-BNC-HON-500G',
            'name' => 'DUMMY Bionic Mangrove Honey 500gm', 'type' => ProductType::FinishedGood,
        ]);

        unset($adabor, $kg, $piece, $bnc);
    }

    /** Everything the sandbox names carries this, so a stray row is obvious. */
    public static function prefix(string $value): string
    {
        return Str::startsWith($value, 'DUMMY') ? $value : 'DUMMY-'.$value;
    }
}
