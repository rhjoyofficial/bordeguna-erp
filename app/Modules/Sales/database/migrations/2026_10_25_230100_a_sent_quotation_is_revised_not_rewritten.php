<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B13 (24 Oct 2026, gap S21; FINAL-PLAN "Quotation, sent: Edit creates
 * revision R1, the sent version is kept"): a sent quotation that is edited
 * stays as it was, marked `revised`, and the change is a new draft
 * "QT-...-R1" whose `revision_of_id` names the copy the customer holds.
 *
 * Nullable, no backfill: every existing quotation is an original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('revision_of_id')->nullable()->after('sales_order_id');
            $table->index('revision_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['revision_of_id']);
            $table->dropColumn('revision_of_id');
        });
    }
};
