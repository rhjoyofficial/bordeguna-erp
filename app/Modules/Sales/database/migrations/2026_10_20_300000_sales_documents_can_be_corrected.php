<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections wave 1, sales documents (FINAL-PLAN 3.2, 20 Oct 2026).
 *
 *   customer_receipts   Reverse and Correct: the Reversible columns, plus the
 *                       allocations the reversal released (json), so the
 *                       History and an ageing as at an earlier day can say
 *                       which invoices it had paid.
 *   deliveries          Reverse (the challan): the Reversible columns, plus
 *                       the quantities it gave back to each order line.
 *   sales_invoices      a full credit note marks the invoice credited (the
 *                       Reversible columns: it stays, its printed fields never
 *                       change); `debit_note_for_invoice_id` names the invoice
 *                       an extra charge was raised on.
 *   sales_credit_notes  the credit note itself: its own CN number, citing the
 *                       invoice. Full (the invoice's journal mirrored) or price
 *                       only (a party adjustment against the invoice).
 *
 * ⚠⚠ The delivery charge "billed once per order" rule moves from a UNIQUE index
 * on `billed_delivery_for_order_id` to one on `live_delivery_for_order_id`. The
 * printed link stays on the line for ever; the live key is cleared when the
 * invoice is credited in full, so its correction can bill the charge again and
 * no second live invoice ever can.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_receipts' => 'receipts', 'deliveries' => 'deliveries', 'sales_invoices' => 'invoices'] as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($short) {
                $t->date('reversed_on')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->unsignedBigInteger('reversed_by')->nullable();
                $t->text('reversal_reason')->nullable();
                $t->unsignedBigInteger('reversal_journal_id')->nullable();

                $t->foreign('reversed_by', "{$short}_reversed_by_fk")->references('id')->on('users')->restrictOnDelete();
                $t->foreign('reversal_journal_id', "{$short}_reversal_jnl_fk")->references('id')->on('journals')->restrictOnDelete();
            });
        }

        Schema::table('customer_receipts', function (Blueprint $t) {
            $t->json('reversed_allocations')->nullable();
        });

        Schema::table('deliveries', function (Blueprint $t) {
            $t->json('reversed_quantities')->nullable();
        });

        Schema::table('sales_invoices', function (Blueprint $t) {
            $t->unsignedBigInteger('debit_note_for_invoice_id')->nullable();
            $t->foreign('debit_note_for_invoice_id', 'invoices_debit_note_for_fk')->references('id')->on('sales_invoices')->restrictOnDelete();
        });

        Schema::table('sales_invoice_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('live_delivery_for_order_id')->nullable();
            // ⚠ The foreign key on billed_delivery_for_order_id needs an index of
            // its own on MySQL before the unique one can go.
            $t->index('billed_delivery_for_order_id', 'invoice_lines_delivery_for_order_idx');
        });

        DB::table('sales_invoice_lines')->whereNotNull('billed_delivery_for_order_id')
            ->update(['live_delivery_for_order_id' => DB::raw('billed_delivery_for_order_id')]);

        Schema::table('sales_invoice_lines', function (Blueprint $t) {
            $t->dropUnique('invoice_lines_delivery_once_unique');
            $t->unique('live_delivery_for_order_id', 'invoice_lines_live_delivery_once_unique');
        });

        Schema::create('sales_credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->restrictOnDelete();
            $t->string('credit_note_number', 40);
            $t->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // full: the whole invoice taken back (its journal mirrored, its
            // challans billable again); price: a lower price on goods kept.
            $t->string('kind', 10);
            $t->date('credit_date');
            $t->decimal('amount', 15, 4);
            $t->text('reason');
            $t->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $t->foreignId('party_adjustment_id')->nullable()->constrained('party_adjustments')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['business_id', 'credit_note_number'], 'sales_credit_notes_number_unique');
            $t->index(['business_id', 'credit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_notes');

        Schema::table('sales_invoice_lines', function (Blueprint $t) {
            $t->dropUnique('invoice_lines_live_delivery_once_unique');
            $t->unique('billed_delivery_for_order_id', 'invoice_lines_delivery_once_unique');
            $t->dropIndex('invoice_lines_delivery_for_order_idx');
            $t->dropColumn('live_delivery_for_order_id');
        });

        Schema::table('sales_invoices', function (Blueprint $t) {
            $t->dropForeign('invoices_debit_note_for_fk');
            $t->dropColumn('debit_note_for_invoice_id');
        });

        Schema::table('deliveries', fn (Blueprint $t) => $t->dropColumn('reversed_quantities'));
        Schema::table('customer_receipts', fn (Blueprint $t) => $t->dropColumn('reversed_allocations'));

        foreach (['customer_receipts' => 'receipts', 'deliveries' => 'deliveries', 'sales_invoices' => 'invoices'] as $table => $short) {
            Schema::table($table, function (Blueprint $t) use ($short) {
                $t->dropForeign("{$short}_reversed_by_fk");
                $t->dropForeign("{$short}_reversal_jnl_fk");
                $t->dropColumn(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
            });
        }
    }
};
