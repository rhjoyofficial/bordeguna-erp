<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a confirmed production run is
 * reversed or corrected through the corrections process (FINAL-PLAN 3.3), so
 * it keeps the rest of the Reversible record beside its existing reversed_by
 * and reversed_at: the date the reversal landed on, why, and its journal.
 * Runs reversed before keep NULL here: nothing is back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_runs', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('reversal_journal_id', 'production_runs_reversal_jnl_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_runs', function (Blueprint $table) {
            $table->dropForeign('production_runs_reversal_jnl_fk');
            $table->dropColumn(['reversed_on', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
