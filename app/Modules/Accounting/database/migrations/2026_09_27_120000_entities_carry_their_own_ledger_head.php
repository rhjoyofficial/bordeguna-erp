<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ EVERY CUSTOMER, SUPPLIER AND EMPLOYEE CARRIES ITS OWN LEDGER HEAD (Joy,
 * 27 Sep 2026), the same way a money account already does (MoneyAccount.account_id).
 *
 * The head is a named, grouped, unique chart row the person sees and picks; the
 * money still rolls up through the control account (Accounts Receivable /
 * Payable / Employee Advances) via the party on the journal line, so the books
 * and reconciliation are unchanged (Interpretation B). This column is the
 * durable, unambiguous link between the entity and its head - display names may
 * repeat, this key does not.
 *
 * Nullable + unique: filled by EntityLedgers when the entity is created or
 * back-filled; one head belongs to at most one entity.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = ['customers', 'suppliers', 'employees'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'account_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('account_id')->nullable()->after('id')
                        ->constrained('accounts')->nullOnDelete();
                    $t->unique('account_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'account_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['account_id']);
                    $t->dropUnique(['account_id']);
                    $t->dropColumn('account_id');
                });
            }
        }
    }
};
