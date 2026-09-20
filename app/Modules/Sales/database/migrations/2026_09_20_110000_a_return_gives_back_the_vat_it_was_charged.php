<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sales return is the credit note (VAT plan D6): it gives VAT back at the
 * rate and in the mode the ORIGINAL invoice line was taxed, never today's.
 *
 * ⚠ Per invoice line credited, because one return can reach back into several
 * invoices raised at different rates, and the last portion of a line must give
 * back exactly what is left of that line's VAT (the same remainder discipline
 * `gross` and `discount` already follow), so nothing strands.
 *
 * Defaults are zero: every return recorded before VAT exists gave none back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_invoice_lines', function (Blueprint $table) {
            // VAT given back on this slice of the invoice line.
            $table->decimal('tax', 15, 4)->default(0);
            // The VAT inside the clawed-back discount share (inclusive only).
            $table->decimal('tax_on_discount', 15, 4)->default(0);
        });

        Schema::table('sales_return_lines', function (Blueprint $table) {
            // VAT sitting INSIDE credit_gross, to be taken out before Sales
            // Returns is debited. Zero when the invoice added VAT on top.
            $table->decimal('tax_inside_gross', 15, 4)->default(0);
            $table->decimal('tax_on_discount', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_lines', fn (Blueprint $table) => $table->dropColumn(['tax_inside_gross', 'tax_on_discount']));
        Schema::table('sales_return_invoice_lines', fn (Blueprint $table) => $table->dropColumn(['tax', 'tax_on_discount']));
    }
};
