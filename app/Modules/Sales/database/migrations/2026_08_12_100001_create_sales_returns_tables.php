<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales returns: goods that left on a challan come back (C-SLS-8, closed by
     * SALES-RETURNS-ARCHITECTURE.md with every Correction answered 2026-08-12).
     *
     * ⚠⚠ THE RETURN IS ANCHORED TO DELIVERY LINES - the physical truth - and the
     * financial treatment is DERIVED per line from how much of it was invoiced
     * (uninvoiced first, C-SR-4). Nobody at the counter chooses "GDNI reversal
     * or AR credit"; the system knows.
     *
     * ⚠ One returned line may SPLIT on inspection (C-SR-3, Joy): part back to
     * stock - restored onto the very batches the sale consumed, newest-consumed
     * first - and part written off, which must never re-enter the stock asset.
     *
     * ⚠ Every explicit name stays under MySQL's 64-character identifier ceiling,
     * and constrained() is never given indexName: - both lessons already paid
     * for on this project (3 Aug, 11 Aug).
     */
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // ⚠ ONE return references ONE delivery - the challan that came
            // back - exactly as a purchase return references one PO.
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();

            $table->string('return_number');
            $table->date('returned_at');

            // ⚠ The NET credit to the customer (gross at invoiced prices less
            // the clawed-back share of any invoice-level discount). Stored so
            // reconciliation can fold it per customer exactly as the posting
            // valued it; zero for a fully-uninvoiced return.
            $table->decimal('credit_total', 15, 4)->default(0);

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'return_number'], 'sales_returns_number_unique');
            $table->index(['business_id', 'customer_id'], 'sales_returns_business_customer_index');
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_line_id')->constrained()->restrictOnDelete();

            // The split (C-SR-3): to_stock + written_off = quantity, enforced
            // by the action, not the schema - SQLite ignores check constraints
            // it cannot parse and a constraint only one driver enforces is a
            // false comfort (the lockForUpdate lesson, structural edition).
            $table->decimal('quantity', 15, 4);
            $table->decimal('quantity_to_stock', 15, 4)->default(0);
            $table->decimal('quantity_written_off', 15, 4)->default(0);

            // ─── The money, stored exactly as posted (C-REC-6) ───────────────
            // GDNI relief is pro-rata-with-remainder over the line's delivered
            // cost; COGS relief is the BALANCING figure (cost restored + cost
            // written off - GDNI relief) and may legitimately be negative.
            $table->decimal('gdni_relief', 15, 4)->default(0);
            $table->decimal('cogs_relief', 15, 4)->default(0);
            $table->decimal('cost_restored', 15, 4)->default(0);
            $table->decimal('cost_written_off', 15, 4)->default(0);

            // Gross at the invoiced price, the pro-rated invoice-discount
            // clawback, and the net the customer is actually credited.
            $table->decimal('credit_gross', 15, 4)->default(0);
            $table->decimal('credit_discount', 15, 4)->default(0);
            $table->decimal('credit_net', 15, 4)->default(0);

            $table->timestamps();

            $table->index(['business_id', 'sales_return_id'], 'sales_return_lines_business_return_index');
        });

        // ⚠⚠ THE CAPACITY LEDGER for layer walks, covering BOTH dispositions.
        // A written-off unit consumes a layer's returnable capacity without
        // writing any stock movement, so deriving capacity from movements alone
        // would let a later return restore the same layer twice - overdrawing
        // GDNI/COGS with every journal balancing. One row per (return line,
        // consumed OUT movement); restored_movement_id carries the IN movement
        // for the back-to-stock rows and stays null for write-offs.
        Schema::create('sales_return_line_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('restored_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 4);
            $table->string('disposition');

            // The layer cost this share was valued at - the batch's frozen
            // cost, snapshotted so a write-off's valuation survives anything
            // that later happens to the batch row.
            $table->decimal('unit_cost', 15, 4);

            $table->timestamps();

            $table->index(
                ['business_id', 'stock_movement_id'],
                'srl_movements_business_movement_index',
            );
        });

        // The FACT of what was credited, per invoice line, walking newest
        // first with remainder discipline. Valuation, not application.
        Schema::create('sales_return_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity', 15, 4);
            $table->decimal('gross', 15, 4);
            $table->decimal('discount', 15, 4)->default(0);
            $table->decimal('net', 15, 4);

            $table->timestamps();

            $table->index(
                ['business_id', 'sales_invoice_line_id'],
                'sri_lines_business_invoice_line_index',
            );
        });

        // The APPLICATION of the net credit against invoices, capped at each
        // invoice's outstanding, newest first. Residue is credit-on-account -
        // warned by reconciliation exactly as an unallocated receipt is.
        Schema::create('sales_return_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 15, 4);

            $table->timestamps();

            $table->index(
                ['business_id', 'sales_invoice_id'],
                'sr_allocations_business_invoice_index',
            );
        });

        // Money physically going back (C-SR-6): return-linked only, its own
        // document, exactly as a supplier payment is separate from a bill.
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 15, 4);
            $table->date('refunded_on');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id'], 'customer_refunds_business_customer_index');
        });

        // ⚠ The returned quantities live on the delivery line beside
        // quantity_invoiced, the pair every cap re-reads under lock.
        // quantity_returned caps the next return; quantity_returned_uninvoiced
        // is what quantityUninvoiced() (the billable remainder) subtracts, so
        // month end simply bills less (C-SR-4).
        Schema::table('delivery_lines', function (Blueprint $table) {
            $table->decimal('quantity_returned', 15, 4)->default(0);
            $table->decimal('quantity_returned_uninvoiced', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_lines', function (Blueprint $table) {
            $table->dropColumn(['quantity_returned', 'quantity_returned_uninvoiced']);
        });

        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('sales_return_allocations');
        Schema::dropIfExists('sales_return_invoice_lines');
        Schema::dropIfExists('sales_return_line_movements');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
    }
};
