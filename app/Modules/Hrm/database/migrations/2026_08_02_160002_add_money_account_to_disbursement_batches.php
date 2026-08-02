<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which account the salaries actually came out of - the live gap R1
     * described in the Accounting review.
     *
     * ⚠ NULLABLE in the schema and REQUIRED at confirmation, because the
     * constraint is about *when*. A batch is created by grouping payslips by
     * payment method before any money moves; the money moves when somebody
     * confirms, which is a separate act with its own timestamp and actor. A
     * NOT NULL column cannot express "not yet".
     *
     * ℹ Production holds zero disbursement batches - the trial payroll produced
     * payslips and nobody was paid through the system - so nothing legacy is
     * being accommodated here.
     */
    public function up(): void
    {
        Schema::table('disbursement_batches', function (Blueprint $table) {
            $table->foreignId('money_account_id')->nullable()->after('method')
                ->constrained('money_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disbursement_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('money_account_id');
        });
    }
};
