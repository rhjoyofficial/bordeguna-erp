<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B22 (Joy, 24 Oct 2026; R10, Q9 = A): a product FAMILY groups
 * separate sizes. "Miniket Rice" is the family; 1 kg, 25 kg and 50 kg are three
 * ordinary products, each with its own SKU, stock, batches and prices, that
 * point at the family and say which size they are.
 *
 * ⚠⚠ A GROUPING, NOTHING MORE. No stock, batch, price, recipe or journal row
 * points at a family, and none ever moves when a product joins or leaves one.
 * That is what makes "single to multiple variants and back" safe: it is a
 * label on the product, not a change to what the product is.
 *
 * - product_families: the parent. `sku` is the base a new size's SKU is
 *   proposed from (MINIKET + 25 kg = MINIKET-25KG); category and brand are
 *   what a new size starts with. The Recycle bin columns: an unused family
 *   (no sizes) may be deleted, the Owner restores it, nothing is purged (Q5).
 * - products.product_family_id + family_attribute_value ("25 kg"). One size
 *   per value in a family (unique). ⚠ RESTRICT: a family with sizes is never
 *   deleted from under them; its members leave first.
 *
 * ⚠ New table and nullable columns only; no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // ⚠ Not unique in the database: a binned family keeps its SKU, and
            // a new family may reuse it. The screen refuses a live clash.
            $table->string('sku', 100);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->string('attribute_name', 60)->default('Packaging size');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('delete_reason')->nullable();

            $table->index(['business_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_family_id')->nullable()->after('brand_id')
                ->constrained('product_families')->restrictOnDelete();
            $table->string('family_attribute_value', 60)->nullable()->after('product_family_id');

            $table->unique(['product_family_id', 'family_attribute_value']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['product_family_id', 'family_attribute_value']);
            $table->dropConstrainedForeignId('product_family_id');
            $table->dropColumn('family_attribute_value');
        });

        Schema::dropIfExists('product_families');
    }
};
