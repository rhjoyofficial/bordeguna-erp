<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 1 of the Stock Count and Backdating architecture.
     *
     * Every quantity in Inventory was stamped only with `created_at`, which
     * means "when the row was written". Nothing recorded when the event actually
     * happened in the warehouse, and while those are the same thing everything
     * works. They stop being the same the moment a delivery arrives on Saturday
     * and is entered on Monday.
     *
     * ⚠ Both are kept, deliberately. `created_at` answers "what did we believe
     * on the 3rd", the new column answers "what was actually on the shelf on the
     * 3rd". An audit needs both, and collapsing them into one editable date
     * destroys the first question permanently.
     *
     * ⚠⚠ `received_at` is not decoration on the batch: FIFO consumption orders
     * by it. Ordering by `created_at` once receipts can be backdated means a
     * batch entered today but received last month sorts last, so first-in
     * -first-out silently begins consuming the newer cost layer.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('note');
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('status');
        });

        // Backfill, so nothing already recorded changes meaning. Every existing
        // row happened when it was written, which was true until this migration.
        DB::table('stock_movements')->whereNull('occurred_at')->update([
            'occurred_at' => DB::raw('created_at'),
        ]);

        DB::table('stock_batches')->whereNull('received_at')->update([
            'received_at' => DB::raw('created_at'),
        ]);

        // Only now can they be required. Adding them NOT NULL up front would
        // fail on any table that already has rows.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable(false)->change();
            $table->index('occurred_at');
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable(false)->change();
            // Composite, because this is exactly the FIFO consumption lookup.
            $table->index(['product_id', 'warehouse_id', 'status', 'received_at'], 'stock_batches_fifo_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['occurred_at']);
            $table->dropColumn('occurred_at');
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropIndex('stock_batches_fifo_index');
            $table->dropColumn('received_at');
        });
    }
};
