<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier lead becomes a supplier (CRM lead slice 2e, plan D12; Joy's answer
 * 13: suppliers are often informal, so only a code and a name are required).
 *
 * ⚠ The mirror of `converted_customer_id`, with the same shape and the same
 * rule: restrictOnDelete, so a supplier that a lead became cannot be deleted
 * out from under the funnel, and one index with the business in front.
 *
 * ⚠ A lead holds at most ONE of the two. ConvertLead refuses a supplier lead and
 * ConvertLeadToSupplier refuses every other type, so the pair can never both be
 * set; a database CHECK is not used because the suite runs on SQLite, where an
 * ALTER that adds one rebuilds the table.
 *
 * Measured on production 15 Sep 2026: 0 leads, so the column starts empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('converted_supplier_id')->nullable()->after('converted_customer_id')
                ->constrained('suppliers')->restrictOnDelete();
            $table->index(['business_id', 'converted_supplier_id'], 'leads_biz_converted_supplier_idx');
        });
    }

    public function down(): void
    {
        // ⚠ Foreign key first, then the index, then the column (MySQL error 1553
        // otherwise, rehearsed 12 Sep 2026).
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_supplier_id']);
            $table->dropIndex('leads_biz_converted_supplier_idx');
            $table->dropColumn('converted_supplier_id');
        });
    }
};
