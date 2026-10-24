<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B13 (24 Oct 2026; Joy's R101, gap S5).
 *
 *   credit_note_number   every new return is also a credit note to the
 *                        customer, numbered in the same CN-yyyy-yy series the
 *                        invoice credit notes use (CreditSalesInvoice). Null on
 *                        returns made before this, and on a reversal row.
 *   reversed_*           a wrong return is Reversed through the corrections
 *                        engine: kept and marked (the Reversible columns).
 *   reverses_return_id   the reversal is written as a MIRROR return (every
 *                        quantity and amount negated, stock taken back out of
 *                        the same batches), so every report that sums returns
 *                        nets it out without learning about reversals.
 *
 * MySQL 8 and SQLite. No backfill: a number is never invented for paper that
 * already went out without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('credit_note_number', 40)->nullable()->after('return_number');
            $table->foreignId('reverses_return_id')->nullable()->after('credit_note_number')->constrained('sales_returns')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();

            $table->unique(['business_id', 'credit_note_number'], 'sales_returns_credit_note_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique('sales_returns_credit_note_unique');
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('reverses_return_id');
            $table->dropColumn(['credit_note_number', 'reversed_on', 'reversed_at', 'reversal_reason']);
        });
    }
};
