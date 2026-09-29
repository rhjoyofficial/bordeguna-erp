<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Book depreciation by asset class (finance decision B4, 29 Sep 2026).
 *
 * The asset register's categories already exist; each now carries the
 * straight-line useful life a new fixed asset of that class defaults to,
 * owner-editable on Assets > Asset categories:
 *
 *   furniture 10 years, computers/IT 3, office equipment 5, vehicles 5,
 *   production machinery 7, leasehold improvement 5 (or the lease, if shorter)
 *
 * ⚠ Book depreciation only. Tax depreciation (Income Tax Act 2023, Third
 * Schedule) is a separate computation and is not what these numbers are.
 *
 * A fixed asset may now name its class; its useful life is still stored on the
 * asset, so changing a class later never re-depreciates what is already booked.
 */
return new class extends Migration
{
    private const LIFE = [
        'FURN' => 120, 'IT' => 36, 'NET' => 36, 'PRN' => 36, 'CAM' => 36,
        'OFF' => 60, 'VEH' => 60, 'SAFE' => 60, 'TOOL' => 84, 'MACH' => 84, 'LHI' => 60,
    ];

    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('default_useful_life_months')->nullable()->after('default_tracking');
        });

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('asset_category_id')->nullable()->after('account_id')->constrained('asset_categories')->nullOnDelete();
        });

        // The two classes the register did not have, for every business that
        // keeps one. Code is the identity: an existing MACH or LHI is left alone.
        $now = now();

        foreach (DB::table('asset_categories')->distinct()->pluck('business_id') as $businessId) {
            $sort = (int) DB::table('asset_categories')->where('business_id', $businessId)->max('sort');

            foreach ([['Production machinery', 'MACH'], ['Leasehold improvement', 'LHI']] as [$name, $code]) {
                if (DB::table('asset_categories')->where('business_id', $businessId)->where('code', $code)->exists()) {
                    continue;
                }

                $sort += 10;
                DB::table('asset_categories')->insert([
                    'business_id' => $businessId, 'name' => $name, 'code' => $code,
                    'default_tracking' => 'individual', 'is_active' => true, 'sort' => $sort,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        foreach (self::LIFE as $code => $months) {
            DB::table('asset_categories')->where('code', $code)->whereNull('default_useful_life_months')
                ->update(['default_useful_life_months' => $months]);
        }
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_category_id');
        });

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn('default_useful_life_months');
        });
    }
};
