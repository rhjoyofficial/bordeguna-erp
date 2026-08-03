<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paying a supplier: the operational document, not an accounting workflow.
     *
     * ⚠⚠ THE ERP COULD CREATE A LIABILITY AND NOT SETTLE ONE. A supplier bill
     * posted to Accounts Payable and nothing anywhere could pay it - the only
     * related action set a status string, which moves no money and reaches no
     * ledger. Payables would have grown forever and every supplier would have
     * read as permanently unpaid. Found by the coverage matrix.
     *
     * ⚠ Joy's principle, restated 2026-08-03: every financial movement
     * originates from a BUSINESS EVENT, and an operational document comes before
     * the accounting. So this is a payment somebody made - who, which account,
     * when, what reference - and the journal follows from it, rather than a
     * journal with a document invented around it.
     */
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            // ⚠ REQUIRED, unlike the disbursement batch's. A batch exists before
            // the money moves, so its account can only be known at
            // confirmation; a payment record IS the money moving, so there is no
            // moment when it legitimately does not know where the cash came
            // from.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            $table->date('paid_on');
            $table->decimal('amount', 15, 4);

            // The supplier's cheque number, the bank's transfer id, the bKash
            // trxID. What somebody quotes when the supplier says they never
            // received it.
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'supplier_id', 'paid_on']);
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            // ⚠⚠ A payment settles SPECIFIC BILLS, and that is what makes
            // Accounts Payable a subsidiary ledger rather than one balance.
            // "Which invoices did this cheque pay" is the question a supplier
            // asks when they disagree, and without allocations the only honest
            // answer is a shrug.
            //
            // ⚠ One row per (payment, bill): paying the same bill twice from one
            // payment is a data-entry slip, not a business case.
            $table->unique(['supplier_payment_id', 'supplier_bill_id']);
            $table->index('supplier_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
