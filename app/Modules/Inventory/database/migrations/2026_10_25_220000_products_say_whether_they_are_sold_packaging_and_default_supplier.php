<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B4 (Joy, 24 Oct 2026; R16 to R18, R22): three product master
 * fields.
 *
 * - `can_be_sold`: "Is sales item". Off means no sales path takes it (order,
 *   counter, invoice, quotation, import, website); the server refuses it, not
 *   only the pickers. Until now a raw material was sellable exactly when it had
 *   a selling price row (F25, Product::scopeSellable), so the backfill keeps
 *   that: every finished good and service stays on, a raw material is on only
 *   when somebody gave it a price. Nothing that sells today stops selling.
 * - `material_kind`: raw | packaging, for raw materials only (null for
 *   anything else). Packaging was told apart only by its category name or a
 *   PK- SKU (F4, F10); the backfill applies that same rule once, after which
 *   the column decides and Joy can change it in the popup.
 * - `default_supplier_id`: the usual vendor, proposed on a new purchase.
 *   ⚠ Named `default_supplier_id`, not `supplier_id`, on purpose: the
 *   supplier "used or never used" scan (ReferenceScan) reads `supplier_id`
 *   columns, and naming a usual vendor must never stop a supplier from being
 *   deleted. Null on delete for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('can_be_sold')->default(true)->after('type');
            $table->string('material_kind', 20)->nullable()->after('can_be_sold');
            $table->foreignId('default_supplier_id')->nullable()->after('material_kind')
                ->constrained('suppliers')->nullOnDelete();
        });

        DB::table('products')
            ->where('type', 'raw_material')
            ->whereNotExists(fn ($prices) => $prices->selectRaw('1')
                ->from('product_prices')
                ->whereColumn('product_prices.product_id', 'products.id'))
            ->update(['can_be_sold' => false]);

        $packagingCategories = DB::table('categories')
            ->whereRaw('lower(trim(name)) like ?', ['packag%'])
            ->pluck('id')
            ->all();

        DB::table('products')
            ->where('type', 'raw_material')
            ->where(fn ($q) => $q->whereIn('category_id', $packagingCategories === [] ? [0] : $packagingCategories)
                ->orWhere('sku', 'like', 'PK-%'))
            ->update(['material_kind' => 'packaging']);

        DB::table('products')
            ->where('type', 'raw_material')
            ->whereNull('material_kind')
            ->update(['material_kind' => 'raw']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_supplier_id');
            $table->dropColumn(['can_be_sold', 'material_kind']);
        });
    }
};
