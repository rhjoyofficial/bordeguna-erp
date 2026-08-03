<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.3 of Accounting.
     *
     * Two small columns, each promised by an earlier migration's comment.
     *
     * ⚠ `money_accounts.account_id`: the Phase 1.1 migration said "each account
     * maps to a Chart of Accounts entry in Phase 2, one nullable column added
     * then, not now". This is then. A bank account is WHERE money is; the
     * ledger account is what its balance is called in the books, and the two are
     * different questions - which is why one is a row here and the other is a
     * row in `accounts`.
     *
     * ⚠ NULLABLE, and it stays nullable. A cash box can exist before anybody has
     * decided which ledger account it rolls up into, and refusing to record a
     * real bank account because its mapping is undecided would lose the fact
     * that the account exists at all.
     *
     * ⚠ `journals.journal_type` is a DISCRIMINATOR, not a switch. See
     * Enums\JournalType: it says what KIND of entry this is so that opening
     * balances can be told apart from ordinary trading later. It must never
     * change what the posting engine DOES, and a structural test enforces that
     * no `match` on it appears in the engine - the same rule that has now
     * survived a seventh inventory adjustment type without a line of change.
     */
    public function up(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('type')->constrained('accounts')->restrictOnDelete();
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->string('journal_type', 30)->default('manual')->after('journal_number');
            $table->index(['business_id', 'journal_type']);
        });
    }

    public function down(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'journal_type']);
            $table->dropColumn('journal_type');
        });
    }
};
