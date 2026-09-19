<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the VAT engine freezes its answers into.
 * ACCOUNTING-COMPLETION-AND-VAT-PLAN-2026-09-20.md, decisions D1-D9.
 *
 * ⚠⚠ EVERY DEFAULT HERE IS "NO VAT". Production holds zero invoices and zero
 * bills on the day this runs, and VAT is switched off on all four businesses,
 * so a row written before the switch is turned carries zeros and `false` and
 * means exactly what it meant yesterday (C-VAT-8).
 *
 * ⚠ What is frozen, and why on the LINE (D2): a document may be re-printed,
 * returned or audited years after a rate or a price group changed. The line
 * therefore keeps the rate it was taxed at, the amount, whether its price
 * already contained the VAT, and how much of the whole-invoice discount fell on
 * it. Nothing downstream ever recomputes those from today's settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // The registration number printed on a tax invoice.
            $table->string('vat_bin', 40)->nullable()->after('vat_regime');
            // D4: what a delivery charge is taxed as. Null = the standard class.
            $table->foreignId('delivery_tax_class_id')->nullable()->after('vat_bin')
                ->constrained('tax_classes')->nullOnDelete();
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            // D1: the date the rate was read as at. v1 = the invoice date.
            $table->date('tax_point_date')->nullable()->after('invoice_date');
            // D2: the switch the clerk saw. The lines hold the truth.
            $table->boolean('prices_include_tax')->default(false)->after('tax_total');
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignId('tax_class_id')->nullable()->after('product_id')
                ->constrained('tax_classes')->nullOnDelete();
            $table->boolean('prices_include_tax')->default(false)->after('tax_amount');
            // This line's share of the whole-invoice discount, in the
            // document's own price basis.
            $table->decimal('discount_share', 15, 4)->default(0)->after('prices_include_tax');
            // The VAT that discount share itself contained. Zero when prices
            // exclude VAT. Kept so revenue and Discounts Allowed can both be
            // posted net of VAT and still balance to the paisa.
            $table->decimal('tax_on_discount', 15, 4)->default(0)->after('discount_share');
        });

        Schema::table('sales_return_lines', function (Blueprint $table) {
            // D6: a return gives VAT back at the ORIGINAL line's frozen rate.
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->decimal('tax_total', 15, 4)->default(0);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->date('tax_point_date')->nullable();
            $table->boolean('prices_include_tax')->default(false);
        });

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->foreignId('tax_class_id')->nullable()->constrained('tax_classes')->nullOnDelete();
            $table->boolean('prices_include_tax')->default(false);
            // D7: VAT charged is not VAT that may be claimed. Recoverable goes
            // to Input VAT; anything else stays in the cost of what was bought.
            $table->boolean('tax_recoverable')->default(false);
        });

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->decimal('tax_total', 15, 4)->default(0);
        });

        // D9: one row per VAT period that has been worked out and filed.
        Schema::create('vat_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('return_number')->nullable();
            $table->string('regime', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft');
            // Signed paisa, the ledger's own unit. Output and input are what the
            // books say for the period; the rest is the arithmetic of the return.
            $table->bigInteger('output_tax_minor')->default(0);
            $table->bigInteger('input_tax_minor')->default(0);
            $table->bigInteger('adjustment_minor')->default(0);
            $table->string('adjustment_note', 500)->nullable();
            $table->bigInteger('credit_brought_forward_minor')->default(0);
            $table->bigInteger('turnover_minor')->default(0);
            $table->decimal('turnover_tax_rate', 8, 4)->default(0);
            $table->bigInteger('net_payable_minor')->default(0);
            $table->bigInteger('credit_carried_forward_minor')->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('filed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            // One return per business per period: a month is filed once.
            $table->unique(['business_id', 'period_start', 'period_end'], 'vat_returns_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_returns');

        Schema::table('supplier_credit_notes', fn (Blueprint $table) => $table->dropColumn('tax_total'));

        Schema::table('supplier_bill_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_class_id');
            $table->dropColumn(['prices_include_tax', 'tax_recoverable']);
        });

        Schema::table('supplier_bills', fn (Blueprint $table) => $table->dropColumn(['tax_point_date', 'prices_include_tax']));
        Schema::table('sales_returns', fn (Blueprint $table) => $table->dropColumn('tax_total'));
        Schema::table('sales_return_lines', fn (Blueprint $table) => $table->dropColumn(['tax_rate', 'tax_amount']));

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_class_id');
            $table->dropColumn(['prices_include_tax', 'discount_share', 'tax_on_discount']);
        });

        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropColumn(['tax_point_date', 'prices_include_tax']));

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_tax_class_id');
            $table->dropColumn('vat_bin');
        });
    }
};
