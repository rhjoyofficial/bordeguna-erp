<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): an approved stock adjustment can be
 * reversed (FINAL-PLAN 3.3, "Reverse into the same batch").
 *
 * The Reversible columns, as on income vouchers: the adjustment, its
 * movements and its journal all stay; the reversal is new movements on the
 * same batches plus a mirror journal, and these columns say when, by whom and
 * why. Nothing is backfilled: no adjustment was ever reversed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('created_by');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('reversed_by', 'inv_adj_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'inv_adj_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropForeign('inv_adj_reversed_by_fk');
            $table->dropForeign('inv_adj_reversal_jnl_fk');
            $table->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
