<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The scannable code, which is NOT the SKU.
     *
     * ⚠⚠ Joy's decision, 2026-08-04, and it is the right one: these are two
     * different business concepts and merging them would be a one-way mistake.
     *
     *   **SKU**     our permanent internal code. It never changes, even if the
     *               packaging, the supplier or the printed code does. It is what
     *               purchase history, cost layers and profitability hang on.
     *   **Barcode** what a scanner reads. It belongs to the PACK, so it changes
     *               when packaging changes or when a supplier reprints it, and a
     *               product can exist before anybody has printed one.
     *
     * Had these shared a column, re-barcoding a product would have rewritten the
     * identifier its entire trading history is keyed to.
     *
     * ⚠ Nullable, because a product is real before its barcode exists - and
     * unique per business WHEN PRESENT. MySQL permits many NULLs in a unique
     * index, so "optional but unique" needs no partial index and no application
     * rule. Same shape as `customers.code`.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('sku');

            // ⚠ Scoped to the business, like every other identifier here. Two
            // businesses may legitimately stock the same manufactured item and
            // therefore the same EAN.
            $table->unique(['business_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'barcode']);
            $table->dropColumn('barcode');
        });
    }
};
