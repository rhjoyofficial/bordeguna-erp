<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ The full purchase order page (purchase redesign PR1, Joy 18 Oct 2026:
 * "no discount, no multiple payments, no shipping/freight, no VAT").
 *
 *   purchase_orders        the supplier's quotation number, the buyer, terms
 *                          and conditions, the order discount as typed, and
 *                          the totals the page showed (paisa), so the show
 *                          page and the print never add the lines up again.
 *   purchase_order_lines   a description, the unit the line was typed in and
 *                          the quantity and rate in THAT unit, the value
 *                          before discounts, the line's own discount and its
 *                          share of the order discount. VAT keeps the dormant
 *                          tax_rate / tax_amount columns of 9 Aug 2026.
 *   purchase_order_charges freight, carriage, labour, insurance, duty, other:
 *                          amount, split basis, payee. Each becomes a landed
 *                          cost on the receipt; `landed_paisa` is how much of
 *                          it already has, so a second delivery takes only
 *                          the rest (no double count).
 *   purchase_order_payment_schedules  the planned part payments.
 *   suppliers.lead_time_days  the days a supplier usually takes, so the
 *                          expected date fills itself.
 *
 * ⚠ Additive. Every new column has a default or is nullable, so every
 * existing order reads exactly as before: `quantity_ordered`, `unit_cost`
 * and `line_total_paisa` keep their meaning (the purchase-unit quantity and
 * the value GRNI and the bill read). Backfill: each existing line's gross
 * value is its stored total (it had no discount), each order's goods and
 * grand total are the sum of its lines plus its (always zero) tax_total.
 * Data touched on a live database: those two UPDATE statements only.
 *
 * MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('supplier_reference', 100)->nullable()->after('po_number');
            $table->foreignId('buyer_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->text('terms_text')->nullable()->after('note');
            // percent | amount, as typed; null = no order discount.
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 4)->nullable();
            $table->bigInteger('goods_paisa')->default(0);
            // Line discounts and the order discount together.
            $table->bigInteger('discount_paisa')->default(0);
            $table->bigInteger('charges_paisa')->default(0);
            // The part of the charges owed to THIS supplier.
            $table->bigInteger('supplier_charges_paisa')->default(0);
            $table->bigInteger('rounding_paisa')->default(0);
            // What the supplier is owed: goods - discounts + VAT + their charges + rounding.
            $table->bigInteger('grand_total_paisa')->default(0);
            // VAT off on the order date: the VAT is part of the item cost (C-ACC-1).
            $table->boolean('vat_in_cost')->default(false);
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('product_id');
            $table->foreignId('unit_id')->nullable()->after('description')->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('entered_quantity', 15, 4)->nullable()->after('unit_id');
            $table->decimal('entered_rate', 15, 6)->nullable()->after('entered_quantity');
            $table->bigInteger('gross_paisa')->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 4)->nullable();
            $table->bigInteger('discount_paisa')->default(0);
            $table->bigInteger('order_discount_paisa')->default(0);
        });

        Schema::create('purchase_order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            // A LandedCostType value.
            $table->string('cost_type', 16);
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount_paisa');
            // value | quantity | weight | direct (one line)
            $table->string('allocation_method', 16)->default('value');
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            // ONE of the two: who is owed (this or another supplier) or who paid.
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            // Null = the type's default treatment; otherwise with its reason.
            $table->string('treatment', 16)->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('landed_paisa')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'purchase_order_id']);
        });

        Schema::create('purchase_order_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->date('due_on');
            $table->bigInteger('amount_paisa');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'purchase_order_id'], 'po_payment_schedules_business_po_index');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->nullable();
        });

        DB::table('purchase_order_lines')->update(['gross_paisa' => DB::raw('line_total_paisa')]);

        DB::statement('UPDATE purchase_orders SET goods_paisa = (SELECT COALESCE(SUM(l.line_total_paisa), 0) FROM purchase_order_lines l WHERE l.purchase_order_id = purchase_orders.id)');
        DB::statement('UPDATE purchase_orders SET grand_total_paisa = goods_paisa + CAST(ROUND(tax_total * 100) AS SIGNED)');
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('lead_time_days'));
        Schema::dropIfExists('purchase_order_payment_schedules');
        Schema::dropIfExists('purchase_order_charges');

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['description', 'unit_id', 'entered_quantity', 'entered_rate', 'gross_paisa', 'discount_type', 'discount_value', 'discount_paisa', 'order_discount_paisa']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['buyer_id']);
            $table->dropColumn(['supplier_reference', 'buyer_id', 'terms_text', 'discount_type', 'discount_value', 'goods_paisa', 'discount_paisa', 'charges_paisa', 'supplier_charges_paisa', 'rounding_paisa', 'grand_total_paisa', 'vat_in_cost']);
        });
    }
};
