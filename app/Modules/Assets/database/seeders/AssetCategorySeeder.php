<?php

namespace App\Modules\Assets\database\seeders;

use App\Modules\Assets\Enums\AssetTracking;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;

/**
 * The starting kinds of thing a business keeps (asset management slice A1),
 * drawn from Joy's list of the Corporate Office, 16 September 2026.
 *
 * ⚠⚠ SEEDED ONLY WHILE A BUSINESS HAS NO CATEGORY AT ALL, as the CRM lists are:
 * once one exists the list is the business's own, and a renamed or retired
 * category is never brought back by the next deploy.
 */
class AssetCategorySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: AssetTracking}> */
    private const STARTING = [
        ['Computers and IT', 'IT', AssetTracking::Individual],
        ['Network', 'NET', AssetTracking::Individual],
        ['Printers and coders', 'PRN', AssetTracking::Individual],
        ['Camera and studio', 'CAM', AssetTracking::Individual],
        ['Production tools', 'TOOL', AssetTracking::Individual],
        ['Office equipment', 'OFF', AssetTracking::Individual],
        ['Small tools and stationery', 'SML', AssetTracking::Quantity],
        ['Furniture', 'FURN', AssetTracking::Individual],
        ['Safety gear', 'SAFE', AssetTracking::Individual],
        ['Vehicles', 'VEH', AssetTracking::Individual],
        ['Production machinery', 'MACH', AssetTracking::Individual],
        ['Leasehold improvement', 'LHI', AssetTracking::Individual],
    ];

    /** Book depreciation life by class, in months (finance decision B4). */
    private const LIFE = [
        'FURN' => 120, 'IT' => 36, 'NET' => 36, 'PRN' => 36, 'CAM' => 36,
        'OFF' => 60, 'VEH' => 60, 'SAFE' => 60, 'TOOL' => 84, 'MACH' => 84, 'LHI' => 60,
    ];

    public function run(): void
    {
        foreach (Business::all() as $business) {
            TenantScope::enter($business->id);

            if (AssetCategory::query()->exists()) {
                continue;
            }

            foreach (self::STARTING as $order => [$name, $code, $tracking]) {
                AssetCategory::create([
                    'business_id' => $business->id,
                    'name' => $name,
                    'code' => $code,
                    'default_tracking' => $tracking,
                    'default_useful_life_months' => self::LIFE[$code] ?? null,
                    'sort' => ($order + 1) * 10,
                ]);
            }
        }

        TenantScope::leave();
    }
}
