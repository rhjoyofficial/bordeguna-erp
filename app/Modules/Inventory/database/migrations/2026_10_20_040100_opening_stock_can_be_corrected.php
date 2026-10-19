<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): opening stock can be corrected into
 * Opening Balance Equity (FINAL-PLAN 3.3: "Correct quantity, cost, batch,
 * expiry, or add a forgotten product, into Opening Balance Equity").
 *
 * A correction keeps the wrong document, marked Corrected, and records a new
 * one for the same warehouse and date that names it (`corrects_id`). So the
 * "once per warehouse" index moves from `warehouse_id` to `live_warehouse_id`,
 * which is the warehouse while the document is live and NULL once it has been
 * corrected: still exactly one LIVE opening stock per warehouse, enforced by
 * the database (MySQL lets a unique index hold any number of NULLs).
 *
 * ⚠ The once-per-warehouse REFUSAL in RecordOpeningStock is unchanged: a
 * second ordinary entry for a warehouse that has any opening stock, live or
 * corrected, is still refused. Only the corrections engine records a successor.
 *
 * `opening_stock_lines.value_paisa`: a corrected line's value, taken from the
 * stock movements that make it up (its batch's opening receipt plus what the
 * correction moved), so the successor's journal is those movements to the
 * paisa. NULL on every line recorded before: those keep valuing themselves
 * from their one movement, exactly as before.
 *
 * ⚠⚠ Order on MySQL: the new unique index is created BEFORE the old one is
 * dropped, because the old one starts with business_id and is the index that
 * column's foreign key leans on; dropping it first is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('live_warehouse_id')->nullable()->after('warehouse_id');
            $table->unsignedBigInteger('corrects_id')->nullable()->after('note');
            $table->date('reversed_on')->nullable()->after('created_by');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('corrects_id', 'opening_stocks_corrects_fk')->references('id')->on('opening_stocks')->restrictOnDelete();
            $table->foreign('reversed_by', 'opening_stocks_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'opening_stocks_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
        });

        DB::table('opening_stocks')->update(['live_warehouse_id' => DB::raw('warehouse_id')]);

        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->unique(['business_id', 'live_warehouse_id'], 'opening_stocks_one_live_per_warehouse');
        });

        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'warehouse_id']);
            $table->index(['business_id', 'warehouse_id']);
        });

        Schema::table('opening_stock_lines', function (Blueprint $table) {
            $table->bigInteger('value_paisa')->nullable()->after('expiry_date');
        });

        // Every movement an opening stock writes (its receipts, and what a
        // correction adds or takes on its batches) names it, as a production
        // run's and an adjustment's do. "Has this batch been used since the
        // opening?" is then one question: any quantity movement that does not.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('opening_stock_id')->nullable()->after('production_run_id');
            $table->foreign('opening_stock_id', 'stock_movements_opening_stock_fk')->references('id')->on('opening_stocks')->restrictOnDelete();
        });

        foreach (DB::table('opening_stock_lines')->whereNotNull('stock_movement_id')->get(['opening_stock_id', 'stock_movement_id']) as $line) {
            DB::table('stock_movements')->where('id', $line->stock_movement_id)->update(['opening_stock_id' => $line->opening_stock_id]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign('stock_movements_opening_stock_fk');
            $table->dropColumn('opening_stock_id');
        });

        Schema::table('opening_stock_lines', function (Blueprint $table) {
            $table->dropColumn('value_paisa');
        });

        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->unique(['business_id', 'warehouse_id']);
        });

        Schema::table('opening_stocks', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'warehouse_id']);
            $table->dropUnique('opening_stocks_one_live_per_warehouse');
            $table->dropForeign('opening_stocks_corrects_fk');
            $table->dropForeign('opening_stocks_reversed_by_fk');
            $table->dropForeign('opening_stocks_reversal_jnl_fk');
            $table->dropColumn(['live_warehouse_id', 'corrects_id', 'reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
