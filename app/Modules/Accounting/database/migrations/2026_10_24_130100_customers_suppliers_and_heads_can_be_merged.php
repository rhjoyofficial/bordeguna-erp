<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections follow-up, B13 (24 Oct 2026; FINAL-PLAN section 7 Phase 4
 * "Merges (customer, supplier, ledger head 'Move balance and retire'), Owner
 * only, dry run first").
 *
 * - customers / suppliers: `merged_into_id` with who and when. A record merged
 *   into another is switched off, kept, and points at the one that was kept.
 * - accounts: the same pointer for a ledger head retired by "Move balance and
 *   retire" (its balance moved by one journal, its history untouched).
 *
 * ⚠ Nullable columns only; no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers', 'accounts'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignId('merged_into_id')->nullable()->constrained($name)->nullOnDelete();
                $table->timestamp('merged_at')->nullable();
                $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers', 'accounts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('merged_into_id');
                $table->dropConstrainedForeignId('merged_by');
                $table->dropColumn('merged_at');
            });
        }
    }
};
