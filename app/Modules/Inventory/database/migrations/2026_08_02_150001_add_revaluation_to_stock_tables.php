<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A1: inventory could never be REVALUED.
     *
     * ⚠⚠ Every adjustment had to change a quantity, so there was no way to
     * change what stock is WORTH without changing how much of it there is. That
     * blocked capitalising a landed cost that arrives on a later invoice,
     * writing stock down to net realisable value, and correcting a mistyped
     * cost - and the only remedy available was to write stock out and back in,
     * fabricating two movements that never physically happened.
     *
     * A revaluation is a movement with `quantity = 0` and a `value_delta`.
     *
     * ⚠ The zero is what keeps it invisible to the quantity chain for free.
     * `StockLedger::balanceForBatch()` sums quantities, so slice 4's derivation
     * and all three verification levels keep working untouched.
     *
     * ⚠ `direction` keeps its meaning: `in` raises value (a debit to
     * inventory), `out` lowers it. No third direction, so nothing that branches
     * on direction changes.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // ⚠ STORED, never recomputed. The quantity on hand changes over
            // time, so `quantity x (new cost - old cost)` is only correct at the
            // instant of posting; recomputing later would silently produce a
            // different number. This project has been bitten four times by
            // decimal handling and this is that lesson applied in advance.
            $table->decimal('value_delta', 15, 4)->nullable()->after('unit_cost');
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            // ⚠ Value lives per COST LAYER. Landed cost attaches to the delivery
            // that incurred it and a write-down to the layer that lost value;
            // revaluing "the product" would mean inventing a spread across
            // layers, which is a valuation rule nobody has asked for.
            $table->foreignId('stock_batch_id')->nullable()->after('warehouse_id')
                ->constrained('stock_batches')->nullOnDelete();

            // The cost this layer is being restated to. Its presence, together
            // with a zero quantity, is what tells the engine this record moves
            // money rather than units - a question about the DATA, so the engine
            // still never branches on adjustment type.
            $table->decimal('new_unit_cost', 15, 4)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('value_delta');
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
            $table->dropColumn('new_unit_cost');
        });
    }
};
