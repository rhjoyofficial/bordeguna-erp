<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PUR-004 (owner decision 14 Oct 2026): once a supplier bill is reversed, its
 * supplier invoice number may be used again for the corrected bill, so the
 * stored number matches the supplier's paper. A number on a LIVE bill stays
 * unique per supplier, enforced by the database as before.
 *
 * ⚠ MySQL has no partial unique index ("unique where not reversed"), so the
 * rule lives in `active_number_key`: equal to `bill_number` while the bill is
 * live and NULL once it is reversed (SupplierBill sets it on every save). A
 * unique index on (business, supplier, active_number_key) then refuses two live
 * bills with one number, and MySQL lets any number of reversed bills (NULLs)
 * share it. SQLite treats NULLs the same way.
 *
 * ⚠ The new index is added BEFORE the old one is dropped, so the business_id
 * foreign key always has an index whose first column is business_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->string('active_number_key')->nullable()->after('bill_number');
        });

        // Backfill: every live bill holds its own number; reversed bills hold none.
        DB::table('supplier_bills')->whereNull('reversed_at')->update(['active_number_key' => DB::raw('bill_number')]);

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->unique(['business_id', 'supplier_id', 'active_number_key'], 'supplier_bills_live_number_unique');
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'supplier_id', 'bill_number']);
            $table->index(['business_id', 'supplier_id', 'bill_number'], 'supplier_bills_number_index');
        });
    }

    public function down(): void
    {
        // ⚠ Refused rather than half-done when a reversed bill now shares its
        // number with a live one: the old index cannot hold both.
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->unique(['business_id', 'supplier_id', 'bill_number']);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropIndex('supplier_bills_number_index');
            $table->dropUnique('supplier_bills_live_number_unique');
            $table->dropColumn('active_number_key');
        });
    }
};
