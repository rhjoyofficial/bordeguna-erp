<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a staff loan's money actually came from.
     *
     * ⚠ NULLABLE in the schema and REQUIRED at disbursement, exactly like
     * `disbursement_batches.money_account_id`. A loan exists as a request long
     * before any money moves, so a NOT NULL cannot express "not yet" - the
     * constraint is about WHEN, and it lives in the action.
     *
     * ⚠⚠ I had recorded this as blocked on a decision from Joy: which account
     * funds a staff loan. It was not blocked. The answer is the same one payroll
     * already uses - the person disbursing chooses at the time - so there was
     * never a default to invent, only a field to add. Worth remembering that
     * "needs a business decision" and "needs a field" look identical until you
     * ask what the user would actually do.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('money_account_id')->nullable()->after('status')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_account_id');
        });
    }
};
