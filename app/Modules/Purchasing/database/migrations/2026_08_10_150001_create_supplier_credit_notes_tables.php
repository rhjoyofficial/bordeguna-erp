<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier's credit note: settling the claim a late return creates.
     *
     * ⚠⚠ A return AFTER the bill leaves GRNI with a DEBIT balance - the
     * supplier owes us for goods we sent back after being billed - and nothing
     * could move that claim onto Accounts Payable. The supplier's AP balance
     * overstated what we would actually pay, forever. This document resolves
     * it: Dr Accounts Payable (party = supplier) / Cr GRNI.
     *
     * ⚠ RETURN-LINKED ONLY, by Joy's decision D-SCN-1 (2026-08-10): every
     * credit note references a purchase return and is capped by that return's
     * billed value. A free-standing credit (price dispute, rebate) is a
     * different event with its own contra decision - a future slice, not a
     * loosening of this one.
     *
     * See SUPPLIER-CREDIT-NOTE-ARCHITECTURE.md, C-SCN-1..4.
     */
    public function up(): void
    {
        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            // ⚠ The business event this credit settles (D-SCN-1). RESTRICT: a
            // return with a credit note behind it is part of the books now.
            $table->foreignId('purchase_return_id')->constrained()->restrictOnDelete();

            // ⚠ The SUPPLIER'S document number (D-SCN-2), exactly as
            // `bill_number`: it is what their statement shows during
            // reconciliation calls.
            $table->string('credit_note_number');
            $table->date('credit_date');
            $table->decimal('total', 15, 4);
            $table->text('note')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'supplier_id', 'credit_note_number'], 'supplier_credit_note_number_unique');
            $table->index(['business_id', 'supplier_id', 'credit_date']);
        });

        Schema::create('supplier_credit_note_allocations', function (Blueprint $table) {
            $table->id();

            // ⚠⚠ EVERY INDEX AND FK NAME ON THIS TABLE IS EXPLICIT. The table
            // name is 32 characters, and Laravel's generated
            // `..._supplier_credit_note_id_foreign` lands at exactly MySQL's
            // 64-character ceiling - one character of drift from aborting a
            // production migration halfway, which is precisely how the payment
            // allocations table failed on 3 August. Sixth encounter with a
            // limit only the production driver enforces.
            $table->foreignId('business_id')
                ->constrained(indexName: 'scn_allocations_business_fk')->cascadeOnDelete();
            $table->foreignId('supplier_credit_note_id')
                ->constrained(indexName: 'scn_allocations_credit_note_fk')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')
                ->constrained(indexName: 'scn_allocations_bill_fk')->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            // ⚠ A credit settles SPECIFIC BILLS, exactly as a payment does -
            // that is what keeps Accounts Payable a subsidiary ledger. One row
            // per (credit note, bill).
            $table->unique(['supplier_credit_note_id', 'supplier_bill_id'], 'supplier_credit_note_bill_unique');
            $table->index('supplier_bill_id', 'scn_allocations_bill_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_note_allocations');
        Schema::dropIfExists('supplier_credit_notes');
    }
};
