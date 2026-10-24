<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B13 (24 Oct 2026; Joy's R100, gap S4, FINAL-PLAN "Courier statement,
 * posted: Reverse posting, then import the corrected statement").
 *
 *   reversed_*    a posted payout reversed through the corrections engine is
 *                 KEPT and marked (status `reversed`), its journal mirrored, its
 *                 parcel claims released
 *   live_marker   1 while the statement stands, NULL once reversed. The two
 *                 "same file / same invoice id" unique indexes now include it,
 *                 so a reversed statement no longer blocks importing the right
 *                 one again (a unique index ignores NULLs on MySQL and SQLite).
 *
 * ⚠ The new indexes are added BEFORE the old ones are dropped: on MySQL the
 * old ones also serve the money_account_id foreign key, which must always
 * have an index whose first column is money_account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_statements', function (Blueprint $table) {
            $table->unsignedTinyInteger('live_marker')->nullable()->default(1);
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
        });

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->unique(['money_account_id', 'statement_reference', 'live_marker'], 'courier_statements_live_reference_unique');
            $table->unique(['money_account_id', 'source_sha256', 'live_marker'], 'courier_statements_live_file_unique');
        });

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->dropUnique('courier_statements_courier_reference_unique');
            $table->dropUnique('courier_statements_courier_file_unique');
        });
    }

    public function down(): void
    {
        Schema::table('courier_statements', function (Blueprint $table) {
            $table->unique(['money_account_id', 'statement_reference'], 'courier_statements_courier_reference_unique');
            $table->unique(['money_account_id', 'source_sha256'], 'courier_statements_courier_file_unique');
        });

        Schema::table('courier_statements', function (Blueprint $table) {
            $table->dropUnique('courier_statements_live_reference_unique');
            $table->dropUnique('courier_statements_live_file_unique');
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['live_marker', 'reversed_on', 'reversed_at', 'reversal_reason']);
        });
    }
};
