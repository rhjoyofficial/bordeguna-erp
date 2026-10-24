<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Corrections follow-up, B13 (24 Oct 2026; FINAL-PLAN 3.2 "Customer: Delete
 * to the bin if never used. Reactivate.", 3.3 "Supplier", the same).
 *
 * - customers / suppliers: the Recycle bin columns (Delete when never used,
 *   restored by the Owner only; no purge, ever).
 *
 * ⚠ Nullable columns only; no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('delete_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('deleted_by');
                $table->dropColumn(['deleted_at', 'delete_reason']);
            });
        }
    }
};
