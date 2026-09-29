<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening receivables and payables, one document per genuinely open invoice or
 * bill at cutover (finance decision A4/A5, 29 Sep 2026).
 *
 * An opening invoice or bill keeps its ORIGINAL date and due date so the ageing
 * is right from day one. It has no lines, no delivery, no goods receipt and no
 * VAT; its one journal is Dr 1140 + customer / Cr 3900 Opening Balance Equity
 * (or Dr 3900 / Cr 2110 + supplier), typed OpeningBalance. Receipts and
 * payments settle it like any other.
 *
 * ⚠ `is_opening` is what every sales and purchases figure filters OUT: money
 * owed from before the ERP is not a sale or a purchase made in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false)->after('total');
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('is_opening');
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn('is_opening');
        });
    }
};
