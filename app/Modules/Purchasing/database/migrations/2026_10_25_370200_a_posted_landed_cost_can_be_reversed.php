<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted landed cost can be
 * reversed through the corrections engine (ReverseLandedCost): its cost comes
 * off the batches still on hand, the part already sold follows the Tk 10,000
 * rule, its journal is mirrored and the supplier bills it raised are reversed
 * with it. The landed cost stays, `posted`, marked with who reversed it, on
 * which day, why, and the mirror journal (the same columns as every
 * Reversible document).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landed_costs', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('posted_by');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('reversed_by', 'landed_costs_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'landed_costs_reversal_journal_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('landed_costs', function (Blueprint $table) {
            $table->dropForeign('landed_costs_reversed_by_fk');
            $table->dropForeign('landed_costs_reversal_journal_fk');
            $table->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
