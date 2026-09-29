<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Party adjustments (finance decision C3, the journal half, 29 Sep 2026):
 * a write-off, a discount or a set-off is a typed subledger document, never a
 * free journal line, so the statement, the ageing and the reconciliation keep
 * reading documents that agree with the ledger.
 *
 *   customer_write_off   Dr 6406 Bad Debts Written Off  / Cr 1140 AR + customer
 *   customer_discount    Dr 4210 Discounts Allowed      / Cr 1140 AR + customer
 *   supplier_discount    Dr 2110 AP + supplier          / Cr 4950 Discounts Received
 *   set_off              Dr 2110 AP + supplier          / Cr 1140 AR + customer
 *
 * ⚠ Every adjustment is allocated to specific invoices and/or bills: it reduces
 * what those documents still owe, exactly as a return credit does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->date('adjusted_on');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'adjusted_on']);
        });

        Schema::create('party_adjustment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->index('sales_invoice_id');
            $table->index('supplier_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_adjustment_allocations');
        Schema::dropIfExists('party_adjustments');
    }
};
