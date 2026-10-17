<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Part B (17 Oct 2026, ChatGPT REPLY-1 Q2): ONE conversion table for
 * every purpose, told apart by `purpose` = purchase | recipe | sale.
 *
 * ⚠⚠ EVERY EXISTING ROW IS A PURCHASE ROW AND IS NOT TOUCHED. The column is
 * defaulted, not backfilled, so a row written before this release reads
 * `purchase` without a single UPDATE. The factor frozen on every stock batch
 * (`stock_batches.conversion_factor`) is a copy and is likewise untouched.
 *
 * ⚠ The unique index changes from (product, date) to (product, from unit,
 * purpose, date): a product may now carry a purchase row and a recipe row,
 * or recipe rows from two units, on the same date. ⚠⚠ MySQL reuses an index
 * whose first column is the foreign-key column to back that key, and the old
 * unique was the index backing `product_id`'s constraint (error 1553 on drop,
 * the stock_requests lesson). So on MySQL the constraint is released first and
 * re-declared after; SQLite needs none of that and keeps its old behaviour.
 *
 * Also here: `products.allows_fractional_stock`, false by default. A count
 * stock unit (pcs, jar, box) is consumed in whole numbers unless a product
 * says it is really split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->string('purpose', 10)->default('purchase')->after('product_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allows_fractional_stock')->default(false)->after('purchase_unit_id');
        });

        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
        }

        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropUnique('puc_product_effective_unique');
            $table->unique(['product_id', 'from_unit_id', 'purpose', 'effective_from'], 'puc_product_unit_purpose_effective_unique');
        });

        if ($isMysql) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $isMysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMysql) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
            });
        }

        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropUnique('puc_product_unit_purpose_effective_unique');
            $table->unique(['product_id', 'effective_from'], 'puc_product_effective_unique');
        });

        if ($isMysql) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allows_fractional_stock');
        });
    }
};
