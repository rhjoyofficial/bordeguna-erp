<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026, FINAL-PLAN 3.5): a fixed asset on the
 * books can be REVERSED (entered by mistake: its purchase entry and every
 * depreciation charge are mirrored) or DISPOSED (sold, scrapped, donated or
 * lost: cost and accumulated depreciation leave the books, with the gain or
 * loss). Either way the asset is kept and marked, and depreciation stops.
 *
 *   reversed_*   the Reversible columns (the corrections engine writes them)
 *   disposed_*   the disposal: its date, how it left, what it fetched, into
 *                which money account, the gain or loss head, and its journal
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedBigInteger('reversal_journal_id')->nullable();

            $table->date('disposed_on')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->string('disposal_method', 20)->nullable();
            $table->decimal('disposal_proceeds', 18, 4)->nullable();
            $table->unsignedBigInteger('disposal_money_account_id')->nullable();
            $table->unsignedBigInteger('disposal_account_id')->nullable();
            $table->text('disposal_reason')->nullable();
            $table->unsignedBigInteger('disposal_journal_id')->nullable();

            $table->foreign('reversed_by', 'fixed_assets_reversed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversal_journal_id', 'fixed_assets_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
            $table->foreign('disposed_by', 'fixed_assets_disposed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('disposal_money_account_id', 'fixed_assets_disposal_money_fk')->references('id')->on('money_accounts')->restrictOnDelete();
            $table->foreign('disposal_account_id', 'fixed_assets_disposal_head_fk')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('disposal_journal_id', 'fixed_assets_disposal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            foreach (['reversed_by', 'reversal_jnl', 'disposed_by', 'disposal_money', 'disposal_head', 'disposal_jnl'] as $fk) {
                $table->dropForeign("fixed_assets_{$fk}_fk");
            }

            $table->dropColumn([
                'reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id',
                'disposed_on', 'disposed_at', 'disposed_by', 'disposal_method', 'disposal_proceeds',
                'disposal_money_account_id', 'disposal_account_id', 'disposal_reason', 'disposal_journal_id',
            ]);
        });
    }
};
