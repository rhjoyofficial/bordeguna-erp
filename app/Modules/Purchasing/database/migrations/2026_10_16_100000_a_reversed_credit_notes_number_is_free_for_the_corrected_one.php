<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bug hunt 16 Oct 2026 (02 M8), the PUR-004 rule for supplier credit notes: once
 * a credit note is reversed, its supplier number may be used again for the
 * corrected one, so the stored number matches the supplier's paper. A number on
 * a LIVE credit note stays unique per supplier, enforced by the database.
 *
 * ⚠ The same shape as the bills migration of 14 Oct: MySQL has no partial
 * unique index, so `active_number_key` holds the number while the credit note is
 * live and NULL once it is reversed (SupplierCreditNote sets it on every save),
 * and the unique index sits on that. The new index is added before the old one
 * is dropped, so the business_id foreign key always has an index to use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->string('active_number_key')->nullable()->after('credit_note_number');
        });

        DB::table('supplier_credit_notes')->whereNull('reversed_at')->update(['active_number_key' => DB::raw('credit_note_number')]);

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->unique(['business_id', 'supplier_id', 'active_number_key'], 'scn_live_number_unique');
        });

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->dropUnique('supplier_credit_note_number_unique');
            $table->index(['business_id', 'supplier_id', 'credit_note_number'], 'scn_number_index');
        });
    }

    public function down(): void
    {
        // ⚠ Refused rather than half-done when a reversed credit note now
        // shares its number with a live one: the old index cannot hold both.
        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->unique(['business_id', 'supplier_id', 'credit_note_number'], 'supplier_credit_note_number_unique');
        });

        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->dropIndex('scn_number_index');
            $table->dropUnique('scn_live_number_unique');
            $table->dropColumn('active_number_key');
        });
    }
};
