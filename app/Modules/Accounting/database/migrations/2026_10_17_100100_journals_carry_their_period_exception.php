<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A journal posted into a closed month under the Owner's one-document exception
 * says so on its own row (PHASE-0-CONTRACT v2, 1.10).
 *
 * ⚠ Written ONCE, at insert, by PostJournal, exactly like reverses_journal_id.
 * A posted journal is never updated, so there is no other moment to write it.
 *
 * ⚠⚠ `period_exception_slot` is the database's last word on "at most N journals
 * per exception" (ChatGPT REPLY-6 1.4): the exception row is locked and the
 * journals under it are counted before each posting, and two writers that both
 * counted 1 would both take slot 2. The UNIQUE pair refuses the second one even
 * on SQLite, where the lock does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->unsignedBigInteger('period_exception_id')->nullable()->after('reverses_journal_id');
            $table->unsignedTinyInteger('period_exception_slot')->nullable()->after('period_exception_id');

            $table->foreign('period_exception_id', 'journals_period_exception_fk')
                ->references('id')->on('closed_period_exceptions')->restrictOnDelete();
            $table->unique(['period_exception_id', 'period_exception_slot'], 'journals_exception_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropForeign('journals_period_exception_fk');
            $table->dropUnique('journals_exception_slot_unique');
            $table->dropColumn(['period_exception_id', 'period_exception_slot']);
        });
    }
};
