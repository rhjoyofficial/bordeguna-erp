<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brands: LaLaDia and Bionic-Garden live inside Bor De Guna as brands rather
 * than as separate tenants.
 *
 * ⚠⚠ A TABLE, NOT A STRING COLUMN ON `products`. Brand is about to become the
 * axis that profit is reported along, and a free-text axis drifts: `LaLaDia`,
 * `Laladia` and `LALADIA` would be three brands and nobody would notice until a
 * margin report was short. The same reasoning made `categories` a table.
 *
 * ⚠ Business-scoped like `categories` and `units_of_measure`, so `BusinessScope`
 * covers it and it fails closed outside a tenant. This design deliberately
 * changes the tenant boundary in NO way: a brand belongs to one business and a
 * product points at a brand in its own business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // ⚠ The import's identity key, frozen once assigned. Keying on the
            // name would create a second brand the first time "Bionic" was
            // corrected to "Bionic-Garden". Same role `sku` plays for products
            // and `code` for suppliers.
            $table->string('code', 20);
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'slug']);
        });

        Schema::table('products', function (Blueprint $table) {
            // ⚠⚠ NULLABLE, and that is a business rule not a convenience.
            // Sugar bought by the sack, empty jars, caps and labels are raw
            // materials with no brand. Forcing a brand on them would mean
            // inventing a "generic" brand, and invented master data is the one
            // thing this onboarding exercise forbids. D-5.
            //
            // ⚠ RESTRICT, not nullOnDelete. A brand with products behind it must
            // not be deletable in a way that silently unbrands a catalogue.
            // Retiring a brand is `is_active = false`, which keeps history
            // readable. Matches `unit_of_measure_id` on the same table.
            $table->foreignId('brand_id')->nullable()->after('category_id')
                ->constrained('brands')->restrictOnDelete();

            $table->index(['business_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'brand_id']);
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });

        Schema::dropIfExists('brands');
    }
};
