<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ PAYMENT TERMS ARE A NUMBER OF DAYS, AND AN INVOICE GETS A DUE DATE (Joy,
 * 26 Sep 2026, Q6: "Yes, terms as a number of 10 days").
 *
 * `customers.payment_terms` was free text ("Net 30", "COD") that nothing could
 * compute with, so an invoice left the due date blank and ageing counted from
 * the invoice date. `credit_days` is the number the books can use: an invoice
 * with no due date given is due `credit_days` after its date, 10 when the
 * customer has no figure of their own (Customer::DEFAULT_CREDIT_DAYS), and 0
 * for the walk-in row, which is never sold on account.
 *
 * ⚠ `payment_terms` stays: what was typed is history, and a note such as
 * "cheque on delivery" still has a place. Nothing reads it for a date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('credit_days')->nullable()->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('credit_days');
        });
    }
};
