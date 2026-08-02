<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1.3 of Accounting: the supplier's own invoice.
     *
     * ⚠⚠ Accounts Payable is a ledger of BILLS, and the document did not exist.
     * All Purchasing had was `purchase_orders.payment_status` - a bare status
     * string with no amount, no number and no date.
     *
     * ⚠ The bill carries its OWN number and its OWN date, which is the entire
     * point. Goods arrive in one month and the bill arrives in another; the
     * difference is Goods Received Not Invoiced, and without it stock exists
     * with no liability behind it and the balance sheet does not balance at
     * month end.
     *
     * ⛔ No journal is posted by any of this. Phase 1 builds the documents;
     * Phase 3 posts them.
     */
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            // ⚠ Nullable, and it must be. Most of a small business's spending
            // never sees a purchase order - rent, electricity, fuel, government
            // fees. A bill that required one would capture a minority of what is
            // actually owed.
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();

            // THEIR number and THEIR dates. Ours are `id` and `created_at`.
            $table->string('bill_number');
            $table->date('bill_date');
            $table->date('due_date')->nullable();

            $table->decimal('total', 15, 4)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // ⚠⚠ The oldest control in accounts payable: the same invoice number
            // from the same supplier twice is how a business pays for one
            // delivery twice, and it happens by accident far more often than by
            // fraud. Enforced by the database, not by a check somebody can skip.
            $table->unique(['business_id', 'supplier_id', 'bill_number']);
            $table->index(['business_id', 'bill_date']);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->cascadeOnDelete();

            // Nullable for the same reason the order is: an electricity bill
            // settles no purchase order line.
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('description')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            // ⚠ STORED, not recomputed. Five decimal incidents on this project;
            // a line total that is derived on every read is a number that can
            // change after the bill was agreed.
            $table->decimal('line_total', 15, 4);

            $table->timestamps();
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            // The third quantity. Ordered, received and invoiced now coexist,
            // and ⚠ their disagreement is not an error to prevent - it is the
            // normal state of a purchase and the thing accounting reports on.
            $table->decimal('quantity_invoiced', 15, 4)->default(0)->after('quantity_received');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_invoiced');
        });

        Schema::dropIfExists('supplier_bill_lines');
        Schema::dropIfExists('supplier_bills');
    }
};
