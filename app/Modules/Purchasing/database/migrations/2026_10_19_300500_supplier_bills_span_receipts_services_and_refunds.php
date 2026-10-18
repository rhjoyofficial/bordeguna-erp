<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bill flexibility (ruling P5, 19 Oct 2026).
 *
 *  1. supplier_bill_lines.goods_receipt_line_id: the delivery (challan) line a
 *     goods line bills, so one bill can cover several receipts and several
 *     orders of one supplier, and a receipt line is never billed beyond what
 *     arrived on it. Null on every line written before this (they billed the
 *     order line only, and still count against it).
 *  2. supplier_bill_lines.line_kind + expense_account_id: a service or other
 *     non-PO line (electricity, repairs) that debits an expense head and
 *     credits Accounts Payable, with no order line behind it.
 *  3. supplier_bills.has_non_po_lines: the flag the purchasing approvals
 *     branch reads to treat such a bill as an exception.
 *  4. supplier_bills.duplicate_override_reason / duplicate_matches: why a
 *     bill that looked like an earlier one was recorded anyway, and which.
 *  5. landed_costs.supplier_bill_id: carriage typed on a supplier's bill goes
 *     through the landed-cost engine; this names the bill it came from.
 *  6. supplier_refunds (SREF-2026-27-0001): the supplier pays back a DEBIT
 *     balance on Accounts Payable (they owe us): Dr the money account /
 *     Cr 2110 with the supplier as party. Reversed with a reason, never edited.
 *
 * Additive only: new nullable columns and defaults, one new table. No row is
 * rewritten, so it is safe on the live chart. MySQL 8 and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_bill_lines', 'goods_receipt_line_id')) {
            Schema::table('supplier_bill_lines', function (Blueprint $table) {
                $table->foreignId('goods_receipt_line_id')->nullable()->after('purchase_order_line_id')
                    ->constrained('goods_receipt_lines', indexName: 'supplier_bill_lines_grn_line_fk')->restrictOnDelete();
                // goods (an order line, matched to its receipt) or expense (a non-PO line).
                $table->string('line_kind', 16)->default('goods')->after('goods_receipt_line_id');
                $table->foreignId('expense_account_id')->nullable()->after('line_kind')
                    ->constrained('accounts', indexName: 'supplier_bill_lines_expense_fk')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('supplier_bills', 'has_non_po_lines')) {
            Schema::table('supplier_bills', function (Blueprint $table) {
                $table->boolean('has_non_po_lines')->default(false)->after('is_opening');
                $table->text('duplicate_override_reason')->nullable()->after('note');
                $table->json('duplicate_matches')->nullable()->after('duplicate_override_reason');
            });
        }

        if (! Schema::hasColumn('landed_costs', 'supplier_bill_id')) {
            Schema::table('landed_costs', function (Blueprint $table) {
                $table->foreignId('supplier_bill_id')->nullable()->after('goods_receipt_id')
                    ->constrained('supplier_bills', indexName: 'landed_costs_supplier_bill_fk')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('supplier_refunds')) {
            Schema::create('supplier_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('number', 30);
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
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

                $table->unique(['business_id', 'number']);
                $table->index(['business_id', 'supplier_id', 'received_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_refunds');

        if (Schema::hasColumn('landed_costs', 'supplier_bill_id')) {
            Schema::table('landed_costs', function (Blueprint $table) {
                $table->dropForeign('landed_costs_supplier_bill_fk');
                $table->dropColumn('supplier_bill_id');
            });
        }

        if (Schema::hasColumn('supplier_bills', 'has_non_po_lines')) {
            Schema::table('supplier_bills', function (Blueprint $table) {
                $table->dropColumn(['has_non_po_lines', 'duplicate_override_reason', 'duplicate_matches']);
            });
        }

        if (Schema::hasColumn('supplier_bill_lines', 'goods_receipt_line_id')) {
            Schema::table('supplier_bill_lines', function (Blueprint $table) {
                $table->dropForeign('supplier_bill_lines_grn_line_fk');
                $table->dropForeign('supplier_bill_lines_expense_fk');
                $table->dropColumn(['goods_receipt_line_id', 'line_kind', 'expense_account_id']);
            });
        }
    }
};
