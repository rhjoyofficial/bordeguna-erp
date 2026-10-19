<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections wave 1, B2 (20 Oct 2026, FINAL-PLAN 3.3 "Purchase return:
 * Reverse"): a purchase return can be reversed, the goods going back into the
 * same batch at the same cost.
 *
 * The Reversible columns, the same shape as supplier payments, credit notes
 * and income vouchers: the return is KEPT and marked, never edited and never
 * deleted. Nothing is backfilled: no return was ever reversed before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('note');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('reversed_by', 'purchase_returns_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'purchase_returns_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropForeign('purchase_returns_reversed_by_fk');
            $table->dropForeign('purchase_returns_reversal_jnl_fk');
            $table->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
