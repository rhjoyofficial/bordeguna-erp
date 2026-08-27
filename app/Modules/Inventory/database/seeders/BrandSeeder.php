<?php

namespace App\Modules\Inventory\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The brands Bor de Guna sells under.
 *
 * ⚠ D-7: LaLaDia and Bionic-Garden only. A future brand is one row and does not
 * need this seeder changed - it can be added through the product screen's brand
 * picker once that exists, or by hand.
 *
 * ⚠⚠ IDEMPOTENT PER BRAND, not all-or-nothing. This project has been bitten by
 * a seeder that checked "have I run before?" once at the top and then skipped
 * everything, so adding a row to it silently did nothing on an existing
 * database. Each brand is matched on (business_id, code) and created only if
 * missing, so re-running is safe and adding a brand later actually adds it.
 *
 * ⚠ Runs INSIDE the tenant. `Brand` is business-scoped and BusinessScope fails
 * closed on `1 = 0`, so a seeder that did not enter the tenant would read an
 * empty table, believe every brand was missing, and write them into no business
 * at all.
 */
class BrandSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string}>
     */
    private const BRANDS = [
        ['code' => 'LLD', 'name' => 'LaLaDia'],
        ['code' => 'BNC', 'name' => 'Bionic-Garden'],
    ];

    public function run(): void
    {
        $business = Business::withoutGlobalScopes()->where('slug', 'bor-de-guna')->first();

        if ($business === null) {
            $this->command?->warn('Bor de Guna business not found; no brands seeded.');

            return;
        }

        TenantScope::enter($business->id);

        try {
            foreach (self::BRANDS as $brand) {
                Brand::firstOrCreate(
                    ['business_id' => $business->id, 'code' => $brand['code']],
                    ['name' => $brand['name'], 'slug' => Str::slug($brand['name']), 'is_active' => true],
                );
            }
        } finally {
            TenantScope::leave();
        }
    }
}
