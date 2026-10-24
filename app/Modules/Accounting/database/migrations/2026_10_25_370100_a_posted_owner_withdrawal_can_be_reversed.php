<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted owner withdrawal
 * can be reversed through the corrections engine: the goods go back onto the
 * batches they left and the drawings journal is mirrored. The document stays,
 * `posted`, marked with who reversed it, on which day, why, and the mirror
 * journal (the same columns as every Reversible document).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_withdrawals', function (Blueprint $table) {
            $table->date('reversed_on')->nullable()->after('posted_by');
            $table->timestamp('reversed_at')->nullable()->after('reversed_on');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_reason');

            $table->foreign('reversed_by', 'owner_withdrawals_reversed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversal_journal_id', 'owner_withdrawals_reversal_journal_fk')->references('id')->on('journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('owner_withdrawals', function (Blueprint $table) {
            $table->dropForeign('owner_withdrawals_reversed_by_fk');
            $table->dropForeign('owner_withdrawals_reversal_journal_fk');
            $table->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        });
    }
};
