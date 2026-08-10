<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price groups become ROWS. Free text on both sides of a match that decides
 * margin is a silent-error generator: a customer in `Distributor` and a rule
 * for `distributor` never meet, the resolver falls through to the retail
 * price, the sale completes, the journal balances, and the distributor was
 * undercharged. A balanced trial balance does not prove the accounting is
 * correct - this table is that principle applied to pricing. C-PRC-1.
 *
 * ⚠ Joy fixed the official codes on 2026-08-10: RETAIL, DEALER, DISTRIBUTOR,
 * CORPORATE, with RETAIL the default for a customer in no group (D-PRC-2).
 * They are planted by PriceGroupSeeder, not here - a migration that writes
 * master data runs once and can never be re-run for a business created later.
 *
 * ⚠ `prices_include_tax` sits on the GROUP (C-PRC-2) because Joy has said
 * retail and B2B prices may follow different tax conventions. It defaults to
 * true and is read by NOTHING while VAT is dormant; recording the seam now is
 * what makes the answer a value change rather than a schema change later.
 *
 * ⚠ Business-scoped like `brands`, and for the same reason: BusinessScope
 * fails closed on it, and a customer may only point at a group in its own
 * business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ The identity key, frozen once assigned - same role `code`
            // plays for suppliers and brands. Matching happens on the FK, so
            // renaming a group's NAME is free; the code is what imports and
            // seeders key on.
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('prices_include_tax')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // ⚠ NULLABLE is the business rule, not a convenience: no group
            // means RETAIL, the default (D-PRC-2). RESTRICT because deleting a
            // group must never silently reprice its customers; retiring one is
            // `is_active = false`.
            $table->foreignId('price_group_id')->nullable()->after('credit_limit')
                ->constrained('price_groups')->restrictOnDelete();
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->foreignId('price_group_id')->nullable()->after('customer_id')
                ->constrained('price_groups')->restrictOnDelete();
        });

        // ⚠ The data migration the free-text columns require (C-PRC-5).
        // Production has 0 grouped rows today, but the sandbox and any scratch
        // dataset may have them, and a migration that only works on the happy
        // database is the kind of thing the scratch-MySQL rule exists to catch.
        // Distinct values are folded to UPPER(TRIM(...)) in PHP - the same
        // case-folding-in-PHP rule the catalogue importer had to learn, because
        // MySQL collation is case-insensitive and SQLite's is not.
        foreach (['customers', 'product_prices'] as $tableName) {
            $rows = DB::table($tableName)
                ->whereNotNull('price_group')
                ->distinct()
                ->get(['business_id', 'price_group']);

            foreach ($rows as $row) {
                $code = mb_strtoupper(trim($row->price_group));

                if ($code === '') {
                    DB::table($tableName)
                        ->where('business_id', $row->business_id)
                        ->where('price_group', $row->price_group)
                        ->update(['price_group' => null]);

                    continue;
                }

                $groupId = DB::table('price_groups')
                    ->where('business_id', $row->business_id)
                    ->where('code', $code)
                    ->value('id');

                if ($groupId === null) {
                    $groupId = DB::table('price_groups')->insertGetId([
                        'business_id' => $row->business_id,
                        'code' => $code,
                        'name' => ucwords(mb_strtolower($code)),
                        'prices_include_tax' => true,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table($tableName)
                    ->where('business_id', $row->business_id)
                    ->where('price_group', $row->price_group)
                    ->update(['price_group_id' => $groupId]);
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('price_group');
            });
        }
    }

    public function down(): void
    {
        foreach (['product_prices', 'customers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('price_group')->nullable();
            });

            // Restore the code as the free-text value, so down() loses no
            // information the up() had.
            $groups = DB::table('price_groups')->get(['id', 'code']);

            foreach ($groups as $group) {
                DB::table($tableName)
                    ->where('price_group_id', $group->id)
                    ->update(['price_group' => $group->code]);
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['price_group_id']);
                $table->dropColumn('price_group_id');
            });
        }

        Schema::dropIfExists('price_groups');
    }
};
