<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales slice 4: the money coming in.
     *
     * ⚠⚠ THE MIRROR OF `supplier_payments`, deliberately identical in shape so
     * that Accounts Receivable and Accounts Payable behave the same way and one
     * mental model covers both. Allocated against specific invoices, outstanding
     * derived from the allocations, over-allocation refused, the invoice
     * re-read under a lock.
     *
     * ⚠ Joy's principle: every financial movement originates from a BUSINESS
     * EVENT. This is somebody receiving money - from whom, into which account,
     * when, with what reference - and the journal follows from it.
     */
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // ⚠ REQUIRED. A receipt IS the money arriving, so there is no moment
            // when it legitimately does not know where the money went.
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            $table->date('received_on');
            $table->decimal('amount', 15, 4);

            // The customer's cheque number, the bKash trxID, the bank reference.
            // What somebody quotes when the customer says they already paid.
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'received_on']);
        });

        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index(['business_id', 'sales_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('customer_receipts');
    }
};
