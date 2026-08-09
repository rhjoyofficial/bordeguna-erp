<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-unit conversion: some goods are bought in one unit and stocked and
 * sold in another. Mustard oil is invoiced by the kilogram and sold by the
 * litre.
 *
 * Design note and the twelve approved Corrections: UNIT-CONVERSION-ARCHITECTURE.md.
 *
 * ⚠⚠ C-UOM-3: THE COLUMN NAMES ITS OWN DIRECTION. It is
 * `stock_units_per_purchase_unit`, never `factor`. A column called `factor`
 * invites someone to store the density (0.917 kg per litre) where the multiplier
 * (1.0905 litres per kilogram) belongs, and every calculation downstream would
 * then be about 21% wrong - silently, for ever, with a perfectly balanced trial
 * balance. The name has to make the wrong value look wrong.
 *
 * ⚠ C-UOM-2: EFFECTIVE-DATED ROWS, not a column on `products`. A column can be
 * edited and an edit carries no date; versioning IS an effective-dated row set.
 * Same shape as `account_mappings`, which already answers "which value was in
 * force on this date".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The unit the supplier invoices in.
            $table->foreignId('from_unit_id')->constrained('units_of_measure')->restrictOnDelete();
            // The unit stock is held and sold in.
            $table->foreignId('to_unit_id')->constrained('units_of_measure')->restrictOnDelete();

            // ⚠ EIGHT decimal places, not four. The factor multiplies every
            // kilogram ever received, so its precision decides cost of goods for
            // ever: 1.09051254 against 1.0905 is a real difference at volume.
            // Quantities stay at four places; the factor does not.
            $table->decimal('stock_units_per_purchase_unit', 15, 8);

            $table->date('effective_from');

            // ⚠ C-UOM checklist item 3. Who says so - supplier invoice,
            // accountant, or measured. Recorded because in a year nobody will
            // remember, and a provisional value must be visibly provisional.
            $table->string('source');
            $table->text('note')->nullable();

            $table->timestamps();

            // ⚠ One factor per product per date. Two rows on the same date would
            // make resolution depend on insertion order.
            $table->unique(['product_id', 'effective_from'], 'puc_product_effective_unique');
            $table->index(['business_id', 'product_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // ⚠ C-UOM-1: NULL means "bought in the same unit it is stocked in",
            // which is every product that exists today and 40 of the 41 in the
            // first catalogue. No backfill, no behaviour change, and the absence
            // of a value honestly says there is no second unit here rather than
            // a flag that has to be kept in step with one.
            $table->foreignId('purchase_unit_id')->nullable()->after('unit_of_measure_id')
                ->constrained('units_of_measure')->restrictOnDelete();
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            // ⚠⚠ C-UOM-4: THE FACTOR IS COPIED HERE, NEVER LOOKED UP AT COGS
            // TIME. If the batch held a foreign key to a conversion row, editing
            // that row next year would change the cost of goods sold last March
            // and the trial balance would still balance. Copying the number
            // makes the batch self-contained - exactly why `unit_cost` already
            // lives on the batch instead of being resolved from a price list.
            $table->decimal('conversion_factor', 15, 8)->nullable()->after('unit_cost');

            // What the supplier actually invoiced, kept so a purchase return can
            // be expressed in the unit the supplier's credit note uses.
            $table->decimal('purchase_quantity', 15, 4)->nullable()->after('initial_quantity');
            $table->foreignId('purchase_unit_id')->nullable()->after('purchase_quantity')
                ->constrained('units_of_measure')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropForeign(['purchase_unit_id']);
            $table->dropColumn(['conversion_factor', 'purchase_quantity', 'purchase_unit_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['purchase_unit_id']);
            $table->dropColumn('purchase_unit_id');
        });

        Schema::dropIfExists('product_unit_conversions');
    }
};
