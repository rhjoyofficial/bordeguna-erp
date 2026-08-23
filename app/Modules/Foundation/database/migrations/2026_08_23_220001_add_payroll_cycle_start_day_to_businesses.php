<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The day of the month each business's PAYROLL cycle starts on.
     *
     * ⚠⚠ Joy's final decision, 23 Aug 2026: Bor De Guna's employee month runs
     * the 24th to the 23rd, and the day must be changeable from Settings
     * rather than living in code. The engine already ran 24-to-23 (Correction
     * C7b hardcoded it); this column is that number made his to change.
     *
     * ⚠⚠ THIS GOVERNS PAYROLL, ATTENDANCE AND PAYROLL-RELATED HR REPORTS ONLY.
     * It is NOT an accounting period. Supplier bills, customer invoices, rent,
     * subscriptions, the financial statements and VAT all keep their own
     * document dates and calendar periods, on his explicit instruction. Any
     * future reader tempted to resolve an accounting date through this column
     * is reading the wrong column.
     *
     * ℹ Default 24, because 24 IS the current behaviour everywhere - C7b
     * applied globally. A default of 1 would silently move every period the
     * moment the code starts reading the column.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('payroll_cycle_start_day')->default(24)->after('fiscal_year_start_month');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('payroll_cycle_start_day');
        });
    }
};
