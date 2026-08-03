<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rent, electricity, fuel, government fees - the spending that never sees a
     * purchase order.
     *
     * ⚠⚠ Probably the MAJORITY OF TRANSACTIONS BY COUNT in a business this size,
     * and until now there was no document for any of it. R4 of the architecture
     * review said so in June and the coverage matrix put a number on it.
     *
     * ⚠ A voucher is PAID WHEN IT IS RECORDED - `money_account_id` is required.
     * That is what a voucher is in a small business: money went out of an
     * account and here is the receipt. Expenses taken on credit are an accrual,
     * a different event, and building them here would create a liability with
     * no settlement path - the exact hole that Accounts Payable had until this
     * week. Deferred deliberately and named in the readiness review.
     */
    public function up(): void
    {
        Schema::create('expense_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('voucher_number', 30);
            $table->date('spent_on');

            // Required: a voucher IS the money leaving.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            // Who it was paid to, when that is somebody the system knows. Most
            // expenses are paid to a landlord or a utility that will never be a
            // supplier record, so this stays optional and `paid_to` carries the
            // name for everyone else.
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('paid_to')->nullable();

            $table->decimal('total', 15, 4);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'voucher_number'], 'expense_voucher_number_unique');
            $table->index(['business_id', 'spent_on']);
        });

        Schema::create('expense_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_voucher_id')->constrained()->cascadeOnDelete();

            // ⚠⚠ THE LINE NAMES ITS ACCOUNT DIRECTLY, and that is the one place
            // this ERP deliberately departs from "posting is derived from
            // mapping configuration, never typed".
            //
            // For a goods receipt the operator is recording stock and should
            // never see an account code. For an expense voucher the
            // classification IS the content of the document: somebody is
            // deciding this was rent rather than fuel, and that decision is the
            // only thing distinguishing one voucher from another. A parallel
            // "expense category" list mapping one-to-one onto the expense
            // accounts would be two lists to keep in step and a second place for
            // them to disagree.
            //
            // ⚠ Restricted to POSTABLE EXPENSE accounts at the action, so the
            // choice is an operational vocabulary ("Rent", "Fuel and
            // Conveyance") rather than a free hand over the ledger.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('description')->nullable();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index('expense_voucher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_voucher_lines');
        Schema::dropIfExists('expense_vouchers');
    }
};
