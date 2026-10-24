<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Retail sellers on consignment (initial plan B21, R109; Joy's Q3 = A,
 * 24 Oct 2026). Our retail employees (Rakib, Nazmul) take goods from stock,
 * sell to customers and shops, and bring back what they could not sell.
 *
 *   warehouses.seller_employee_id   the HR employee whose "seller stock" this
 *                                   location is. Null for every ordinary
 *                                   warehouse. Unique: one seller stock each.
 *                                   No counter, order, challan or transfer
 *                                   screen sells or moves from it; only the
 *                                   seller sheets below do.
 *   seller_transfers (+ lines)      a hand-over sheet (kind handover: goods
 *                                   from a warehouse into his seller stock,
 *                                   valued at Trade Price for the paper he
 *                                   signs, moved at cost) or a return of
 *                                   unsold goods (kind return: back to a
 *                                   warehouse). Each line names the stock
 *                                   transfer that moved it. Reversible.
 *   seller_sales                    "report sales": what he sold, to whom,
 *                                   the counter-sale invoice it made from his
 *                                   seller stock, and where the money went.
 *                                   Its correction is the invoice's own Void
 *                                   sale (the engine), so it carries no
 *                                   reversal columns: the invoice's state is
 *                                   the truth.
 *
 * Additive; new tables only, plus one nullable column without a foreign key
 * (the house rule for columns added to an existing table on SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_employee_id')->nullable()->after('is_active');
            $table->unique('seller_employee_id');
        });

        Schema::create('seller_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('kind', 20);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('seller_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('other_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('moved_on');
            $table->decimal('trade_value', 15, 4)->default(0);
            $table->decimal('cost_value', 15, 4)->default(0);
            $table->string('reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->unsignedBigInteger('reversal_journal_id')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'employee_id', 'moved_on']);
        });

        Schema::create('seller_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->decimal('trade_price', 15, 4)->default(0);
            $table->decimal('cost_value', 15, 4)->default(0);
            $table->foreignId('stock_transfer_id')->nullable()->constrained('stock_transfers')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seller_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('seller_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('sold_on');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('sold_to', 255)->nullable();
            $table->string('price_basis', 20);
            $table->string('payment', 20);
            $table->foreignId('money_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('total', 15, 4)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'employee_id', 'sold_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_sales');
        Schema::dropIfExists('seller_transfer_lines');
        Schema::dropIfExists('seller_transfers');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique(['seller_employee_id']);
            $table->dropColumn('seller_employee_id');
        });
    }
};
