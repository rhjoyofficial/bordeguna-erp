<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WMS bins phase 1 (C-BIN): a named shelf location per warehouse, and a
 * nullable placement on the batch.
 *
 * ⚠⚠ A PLACEMENT RECORD, NOT A STOCK DOCUMENT. `bin_id` moves no value and
 * changes no quantity; the production run remains the sixth and last
 * sanctioned stock document. Null means "not placed", which is every batch on
 * the day this lands, so nothing changes for a business that never creates a
 * bin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // A bin dies with its warehouse: the warehouse controller already
            // refuses to delete a warehouse that holds stock, and a bin in a
            // deleted empty warehouse names a shelf that no longer exists.
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->string('code', 20);
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // C-BIN-3: "A-1" may exist in every warehouse, never twice in one.
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            // ⚠ RESTRICT, not nullOnDelete - the brand rule. Deleting a bin
            // that history references would silently unplace goods; retiring a
            // bin is `is_active = false`.
            $table->foreignId('bin_id')->nullable()->after('warehouse_id')
                ->constrained('warehouse_bins')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropForeign(['bin_id']);
            $table->dropColumn('bin_id');
        });

        Schema::dropIfExists('warehouse_bins');
    }
};
