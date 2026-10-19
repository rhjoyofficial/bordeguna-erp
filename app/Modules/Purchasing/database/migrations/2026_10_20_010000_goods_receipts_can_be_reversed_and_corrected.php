<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1, purchase documents (FINAL-PLAN 3.3, Joy 19 Oct 2026:
 * "everywhere edit or fixing function must have").
 *
 *  - goods_receipts: the Reversible columns (kept and marked, never edited,
 *    never deleted). A receipt is reversed when its goods are untouched, or
 *    corrected (reversed + a new receipt on the true date, quantity or lot);
 *    the wrong one stays visible, marked.
 *  - goods_receipts.details_fixed_at and purchase_orders.details_fixed_at: the
 *    small "Edited" badge after Fix batch details / Fix details. What changed,
 *    who and why is in the History (activity log `corrections`).
 *
 * ⚠ Structure only. Every existing receipt stays live (reversed_at null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('note');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');
            $table->timestamp('details_fixed_at')->nullable()->after('reversal_journal_id');

            $table->foreign('reversed_by', 'goods_receipts_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'goods_receipts_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
            $table->index('reversed_at', 'goods_receipts_reversed_at_idx');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('details_fixed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign('goods_receipts_reversed_by_fk');
            $table->dropForeign('goods_receipts_reversal_jnl_fk');
            $table->dropIndex('goods_receipts_reversed_at_idx');
            $table->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id', 'details_fixed_at']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('details_fixed_at');
        });
    }
};
