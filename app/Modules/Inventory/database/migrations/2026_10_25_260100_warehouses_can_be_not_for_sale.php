<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B7 (Joy, 24 Oct 2026; Q7 = A, R103): a warehouse can be
 * "Not for sale" (quarantine). Damaged goods wait there; nothing is sold,
 * used in production, issued to staff or sent on from it. What is reusable
 * moves back to stock (with a reason), the rest is written off as "Damaged
 * returns".
 *
 *  1. warehouses.not_for_sale (default false: every existing warehouse keeps
 *     selling exactly as before).
 *  2. One "Damaged / Quarantine" warehouse per business that already keeps
 *     stock in a warehouse and has none flagged yet. ⚠ Never for a business
 *     with no warehouse at all: its first warehouse becomes the default, and
 *     a quarantine store must never be that one. Code QRN, or QRN2, QRN3 ...
 *     when the code is taken. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouses', 'not_for_sale')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->boolean('not_for_sale')->default(false)->after('is_active');
            });
        }

        $businesses = DB::table('warehouses')->select('business_id')->distinct()->pluck('business_id');

        foreach ($businesses as $businessId) {
            if (DB::table('warehouses')->where('business_id', $businessId)->where('not_for_sale', true)->exists()) {
                continue;
            }

            $code = 'QRN';
            for ($n = 2; DB::table('warehouses')->where('business_id', $businessId)->where('code', $code)->exists(); $n++) {
                $code = "QRN{$n}";
            }

            DB::table('warehouses')->insert([
                'business_id' => $businessId,
                'name' => 'Damaged / Quarantine',
                'code' => $code,
                'is_default' => false,
                'is_active' => true,
                'not_for_sale' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // A seeded quarantine store nothing ever touched goes; one with
        // history stays (its batches point at it), only the flag column goes.
        DB::table('warehouses')->where('name', 'Damaged / Quarantine')->where('not_for_sale', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_movements')->whereColumn('stock_movements.warehouse_id', 'warehouses.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_batches')->whereColumn('stock_batches.warehouse_id', 'warehouses.id'))
            ->delete();

        if (Schema::hasColumn('warehouses', 'not_for_sale')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn('not_for_sale');
            });
        }
    }
};
