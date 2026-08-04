<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales slice 3: the revenue.
     *
     * ⚠⚠ THE INVOICE IS WHERE THE BUSINESS EARNS SOMETHING, and where the cost
     * of what it sold is released from Goods Delivered Not Invoiced into Cost of
     * Goods Sold. Both halves land in the same journal on the same date, which
     * is the entire reason GDNI exists: a challan raised in July and invoiced in
     * August must put its cost in August, with the revenue it earned.
     *
     * ⛔ NO TAX IS CALCULATED. C-SLS-3 is deferred to Joy's accountant, and
     * every amount here is TAX-EXCLUSIVE with an explicit zero tax. See the
     * warning on `tax_amount` below - the deferral has a real cost.
     */
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // ⚠ OURS. The mirror of a supplier bill, whose number is theirs.
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            // ⚠⚠ STORED, never recomputed on read. Six decimal incidents on this
            // project; a total that is derived on every read is a number that
            // can change after the customer agreed it.
            $table->decimal('subtotal', 15, 4)->default(0);

            // ⚠ Contra-revenue, posted to 4210 Discounts Allowed. A LINE
            // discount is not here - it reduces the line, and revenue is
            // credited net, because a line discount is a decision about what
            // that product was worth. An invoice discount is a decision about
            // the whole bill (C-SLS-5).
            $table->decimal('invoice_discount', 15, 4)->default(0);

            // ⚠⚠ ALWAYS ZERO FOR NOW, and the column exists so the seam is real
            // rather than notional. If the accountant comes back and says prices
            // were VAT-INCLUSIVE all along, every invoice raised before that day
            // overstates revenue and must be restated. The window is only safe
            // while it is short. Mirrors the purchase side's
            // 2026_08_02_150003_document_unit_cost_tax_assumption.
            $table->decimal('tax_total', 15, 4)->default(0);

            $table->decimal('total', 15, 4)->default(0);

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'invoice_number']);
            $table->index(['business_id', 'invoice_date']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();

            // ⚠⚠ NULLABLE, and it must be. A SERVICE has no stock, no delivery
            // and no cost of goods sold - a delivery charge or an installation
            // fee is invoiced directly. Exactly why
            // `supplier_bill_lines.purchase_order_line_id` is nullable.
            //
            // ⚠ One invoice line bills ONE delivery line, for a quantity up to
            // what is still uninvoiced on it. That is what lets a single invoice
            // cover several challans and lets one challan be invoiced in parts.
            $table->foreignId('delivery_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('description')->nullable();
            $table->decimal('quantity', 15, 4);

            // ⚠ COPIED from the price resolver at the moment of sale, never
            // looked up again. An invoice shows what was charged, not what the
            // rule says today - the same reasoning as `unit_cost` on a movement.
            $table->decimal('unit_price', 15, 4);
            $table->decimal('line_discount', 15, 4)->default(0);
            $table->decimal('line_net', 15, 4);

            // The seam. Always 0 until C-SLS-3 is answered.
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);

            // ⚠⚠ WHAT THIS LINE RELEASED FROM GDNI, in taka, stored rather than
            // recomputed. Partial invoicing rounds, and pro-rata fractions do
            // not necessarily sum back to the whole - so when the LAST portion
            // of a delivery line is invoiced the engine releases whatever cost
            // remains rather than another rounded fraction. That rule is only
            // exact if each release is recorded, and this is where.
            //
            // Without it, a fully invoiced challan could leave a few paisa
            // stranded in GDNI forever, and a bridge account that never quite
            // clears is indistinguishable from a real unbilled balance.
            $table->decimal('cost_released', 15, 4)->default(0);

            $table->timestamps();

            $table->index(['business_id', 'sales_invoice_id']);
            $table->index(['business_id', 'delivery_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
    }
};
