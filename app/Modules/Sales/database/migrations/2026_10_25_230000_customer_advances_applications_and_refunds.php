<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Customer advances (Joy's initial plan R90, B11, 24 Oct 2026): money a
 * customer pays with no invoice and no order behind it, used against their
 * invoices later, or paid back. The mirror of the supplier advances
 * (2026_10_19_100000), on the liability side.
 *
 *   customer_advances               CADV-2026-27-0001. Kind `receipt`: money
 *                                   received on account, Dr the money account /
 *                                   Cr 2150 Advances from Customers (party = the
 *                                   customer). Kind `from_order`: what a
 *                                   cancelled order still held, moved to the
 *                                   customer's advance, Dr 1140 / Cr 2150.
 *   customer_advance_applications   CAAP-2026-27-0001: one advance (or the
 *                                   customer's on-account 2150 balance from
 *                                   vouchers, when `customer_advance_id` is
 *                                   null) used against one invoice: Dr 2150 /
 *                                   Cr 1140, both with the customer. The invoice
 *                                   is reduced through a
 *                                   `customer_advance_applied` party adjustment,
 *                                   so outstanding, ageing, the statements and
 *                                   the AR reconciliation read it unchanged.
 *   customer_advance_refunds        CARF-2026-27-0001: the advance paid back,
 *                                   Dr 2150 / Cr the money account.
 *
 * ⚠ All three are corrected by reversal with a reason (the Reversible
 * columns) through the corrections engine, never edited or deleted, and only
 * in dependency order.
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            // receipt, from_order
            $table->string('kind', 20)->default('receipt');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Null for a move from an order (no money moved).
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            // The cancelled order whose money this was (kind from_order).
            $table->foreignId('sales_order_id')->nullable()->constrained()->restrictOnDelete();
            // The receipt typed on the same screen, when the money paid invoices too.
            $table->foreignId('customer_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->decimal('amount', 15, 4);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'customer_advances_number_unique');
            $table->index(['customer_id', 'received_on']);
            $table->index('sales_order_id');
        });

        Schema::create('customer_advance_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Null: the customer's on-account 2150 balance from vouchers and journals.
            $table->foreignId('customer_advance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->date('applied_on');
            $table->decimal('amount', 15, 4);
            // The `customer_advance_applied` adjustment that reduces the invoice;
            // kept and marked reversed with the application.
            $table->foreignId('party_adjustment_id')->nullable()->constrained()->nullOnDelete();
            // For an on-account application: the vouchers or journals it drew on.
            $table->string('source_note')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'customer_advance_applications_number_unique');
            $table->index('customer_advance_id');
            $table->index('sales_invoice_id');
            $table->index(['customer_id', 'applied_on']);
        });

        Schema::create('customer_advance_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Null: paid back out of the on-account 2150 balance.
            $table->foreignId('customer_advance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->date('refunded_on');
            $table->decimal('amount', 15, 4);
            $table->string('reference')->nullable();
            $table->string('source_note')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'customer_advance_refunds_number_unique');
            $table->index('customer_advance_id');
            $table->index(['customer_id', 'refunded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_advance_refunds');
        Schema::dropIfExists('customer_advance_applications');
        Schema::dropIfExists('customer_advances');
    }
};
