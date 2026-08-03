<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which account a single salary payment came out of.
     *
     * ⚠⚠ The batch already recorded this and the individual payment did not, so
     * paying one person and paying nine left different records - and only the
     * batch reached the ledger. The coverage matrix found it. This column is
     * what lets the two paths become one.
     *
     * ⚠ Nullable, because a FAILED or REVERSED payment event carries no money
     * movement of its own, and historical rows predate the column.
     */
    public function up(): void
    {
        Schema::table('payslip_payments', function (Blueprint $table) {
            $table->foreignId('money_account_id')->nullable()->after('method')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payslip_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_account_id');
        });
    }
};
