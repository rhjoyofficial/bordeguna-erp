<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PUR-003 (owner decision 14 Oct 2026): a supplier payment, a supplier credit
 * note and an expense voucher are corrected by REVERSING them, as a supplier
 * bill already is. The document is kept and marked; a mirror journal undoes
 * its entry.
 *
 *   reversed_on           the reversal journal's date (today, or the original
 *                         date when the user chose it and that period is open)
 *   reversed_at / _by     when and by whom
 *   reversal_reason       required: "why was this reversed?" is the first
 *                         question an auditor asks
 *   reversal_journal_id   the mirror journal
 *   reversed_allocations  (payments, credit notes) the bills it had settled,
 *                         kept as a record once the allocations are released
 *
 * ⚠ Index and foreign key names are explicit and short: `supplier_credit_notes`
 * plus a long column name runs close to MySQL's 64-character limit.
 */
return new class extends Migration
{
    private const TABLES = [
        'supplier_payments' => ['prefix' => 'sp', 'allocations' => true],
        'supplier_credit_notes' => ['prefix' => 'scn', 'allocations' => true],
        'expense_vouchers' => ['prefix' => 'ev', 'allocations' => false],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $shape) {
            Schema::table($name, function (Blueprint $table) use ($shape) {
                $table->date('reversed_on')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()
                    ->constrained('users', indexName: "{$shape['prefix']}_reversed_by_fk")->restrictOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->foreignId('reversal_journal_id')->nullable()
                    ->constrained('journals', indexName: "{$shape['prefix']}_reversal_journal_fk")->restrictOnDelete();

                if ($shape['allocations']) {
                    $table->json('reversed_allocations')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name => $shape) {
            Schema::table($name, function (Blueprint $table) use ($shape) {
                $table->dropForeign("{$shape['prefix']}_reversed_by_fk");
                $table->dropForeign("{$shape['prefix']}_reversal_journal_fk");
                $table->dropColumn(array_merge(
                    ['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id'],
                    $shape['allocations'] ? ['reversed_allocations'] : [],
                ));
            });
        }
    }
};
