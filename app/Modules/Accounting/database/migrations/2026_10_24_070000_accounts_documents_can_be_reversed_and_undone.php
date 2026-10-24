<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections wave 2, accounts (B7, 24 Oct 2026; FINAL-PLAN 3.1 and 4).
 *
 *   money_transfers, inter_business_transfers, party_adjustments
 *       the Reversible columns: the document is KEPT and marked, never edited
 *       and never deleted. A party adjustment also keeps the invoices and
 *       bills it had settled (`reversed_allocations`), as a supplier payment
 *       and a customer receipt already do, because its allocation rows are
 *       released so every invoice and bill owes again.
 *   cheque_statuses
 *       "Undo status": the row is kept and marked undone (who, when, why), so
 *       the register reads the cheque as pending again without losing what was
 *       said before. One row per voucher stays (the unique index), reused if
 *       the cheque is marked again; the History keeps every step.
 *   bank_statement_imports
 *       "Remove import": the statement lines go (they are the bank's copy, not
 *       a posting), the import row stays marked removed, so the same file may
 *       be imported again and the removal is on record.
 *
 * Nothing is backfilled: none of these was ever reversed, undone or removed.
 */
return new class extends Migration
{
    private const REVERSIBLE = [
        'money_transfers' => 'money_transfers',
        'inter_business_transfers' => 'ib_transfers',
        'party_adjustments' => 'party_adjustments',
    ];

    public function up(): void
    {
        foreach (self::REVERSIBLE as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($table, $short) {
                $t->date('reversed_on')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('reversed_by')->nullable();
                $t->text('reversal_reason')->nullable();
                $t->unsignedBigInteger('reversal_journal_id')->nullable();

                if ($table === 'party_adjustments') {
                    $t->json('reversed_allocations')->nullable();
                }

                $t->foreign('reversed_by', "{$short}_reversed_by_fk")->references('id')->on('users')->restrictOnDelete();
                $t->foreign('reversal_journal_id', "{$short}_reversal_jnl_fk")->references('id')->on('journals')->restrictOnDelete();
            });
        }

        Schema::table('cheque_statuses', function (Blueprint $t) {
            $t->timestamp('undone_at')->nullable();
            $t->unsignedBigInteger('undone_by')->nullable();
            $t->text('undo_reason')->nullable();
            $t->foreign('undone_by', 'cheque_statuses_undone_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('bank_statement_imports', function (Blueprint $t) {
            $t->timestamp('removed_at')->nullable();
            $t->unsignedBigInteger('removed_by')->nullable();
            $t->text('remove_reason')->nullable();
            $t->foreign('removed_by', 'bank_imports_removed_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $t) {
            $t->dropForeign('bank_imports_removed_by_fk');
            $t->dropColumn(['removed_at', 'removed_by', 'remove_reason']);
        });

        Schema::table('cheque_statuses', function (Blueprint $t) {
            $t->dropForeign('cheque_statuses_undone_by_fk');
            $t->dropColumn(['undone_at', 'undone_by', 'undo_reason']);
        });

        foreach (self::REVERSIBLE as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($table, $short) {
                $t->dropForeign("{$short}_reversed_by_fk");
                $t->dropForeign("{$short}_reversal_jnl_fk");
                $t->dropColumn(array_filter(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id', $table === 'party_adjustments' ? 'reversed_allocations' : null]));
            });
        }
    }
};
