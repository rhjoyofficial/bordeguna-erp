<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Structured payment terms and supplier credit limits (ruling P6, 18 Oct
 * 2026).
 *
 * - suppliers: the kind of terms (immediate, net N days, end of month + N,
 *   advance) and N, plus an optional credit limit. ⚠ The limit WARNS, it never
 *   blocks: nothing anywhere refuses a purchase on it.
 * - purchase_orders: the same pair, NULL meaning "the supplier's terms".
 * - supplier_bills: the terms the bill was recorded on, copied, and where its
 *   due date came from (terms / typed / override) with the reason for an
 *   override. `due_date` already exists and keeps its meaning.
 *
 * ⚠ `suppliers.payment_terms` (free text) stays: what was typed is history.
 * The next migration copies only what reads unambiguously into the new pair.
 * Every new column is nullable, so no existing row changes meaning: a bill
 * with no due date is still aged from its bill date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('payment_terms_type', 20)->nullable()->after('payment_terms');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('payment_terms_type');
            $table->decimal('credit_limit', 15, 4)->nullable()->after('payment_terms_days');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('payment_terms_type', 20)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->string('payment_terms_type', 20)->nullable()->after('due_date');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('payment_terms_type');
            $table->string('due_date_source', 10)->nullable()->after('payment_terms_days');
            $table->string('due_date_reason', 500)->nullable()->after('due_date_source');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn(['payment_terms_type', 'payment_terms_days', 'due_date_source', 'due_date_reason']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_terms_type', 'payment_terms_days']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['payment_terms_type', 'payment_terms_days', 'credit_limit']);
        });
    }
};
