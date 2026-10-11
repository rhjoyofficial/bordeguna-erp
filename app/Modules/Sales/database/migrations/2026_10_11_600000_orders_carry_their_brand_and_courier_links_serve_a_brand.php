<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision, Joy, 11 Oct 2026: every LaLaDia and Bionic order is recorded
 * in the Bor de Guna business, and each order carries its BRAND so sales,
 * campaigns, parcels and profit can be read per brand.
 *
 * ⚠ `sales_orders.brand_id` is NULLABLE: orders taken before today have no
 * recorded brand and are left alone ("No brand" in every filter). Guessing one
 * from the products would be invented data.
 *
 * ⚠ RESTRICT on delete, as on products: retiring a brand is `is_active = false`.
 * A null-on-delete would silently turn a brand's courier link into an
 * any-brand one, which would route the other brand's parcels through it.
 *
 * ⚠⚠ A COURIER LINK SERVES A BRAND (null = any brand), so one account may be
 * linked to one business more than once, a row per brand. The new unique
 * (account, business, brand) is created BEFORE the old (account, business) one
 * is dropped: MySQL's foreign key on courier_account_id needs an index leading
 * on that column at every moment, or the drop fails with error 1553.
 * MySQL treats NULLs as distinct in a unique index, so "one any-brand link per
 * account per business" is kept by the one writer (CourierSettingsController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('store_id')
                ->constrained('brands')->restrictOnDelete();

            $table->index(['business_id', 'brand_id'], 'sales_orders_business_brand_index');
        });

        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('business_id')
                ->constrained('brands')->restrictOnDelete();
        });

        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->unique(['courier_account_id', 'business_id', 'brand_id'], 'courier_link_account_business_brand_unique');
        });

        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->dropUnique(['courier_account_id', 'business_id']);
        });
    }

    public function down(): void
    {
        // ⚠ Fails, as it should, while one account holds two links in one
        // business: collapsing them would have to choose which one to lose.
        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->unique(['courier_account_id', 'business_id']);
        });

        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->dropUnique('courier_link_account_business_brand_unique');
        });

        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });

        Schema::table('courier_account_business', function (Blueprint $table) {
            $table->dropColumn('brand_id');
        });

        // The foreign key first: the composite index is not the one it uses, but
        // the order that is always safe on MySQL is constraint, index, column.
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_business_brand_index');
            $table->dropColumn('brand_id');
        });
    }
};
