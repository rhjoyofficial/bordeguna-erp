<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A: never used = Recycle bin, the Owner
 * restores; used = Switch off with a reason).
 *
 * The Recycle bin columns on the organization units: offices, departments,
 * sections and designations.
 *
 * ⚠ Nullable columns only; no existing row changes.
 */
return new class extends Migration
{
    private const TABLES = ['offices', 'departments', 'sections', 'designations'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('delete_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('deleted_by');
                $table->dropColumn(['deleted_at', 'delete_reason']);
            });
        }
    }
};
