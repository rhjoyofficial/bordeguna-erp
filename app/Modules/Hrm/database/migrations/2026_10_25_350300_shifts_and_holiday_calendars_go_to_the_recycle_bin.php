<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A: never used = Recycle bin, the Owner
 * restores; used = Switch off with a reason).
 *
 * The Recycle bin columns on shifts and holiday calendars (a calendar's own
 * holidays stay with it in the bin).
 *
 * ⚠ Nullable columns only; no existing row changes.
 */
return new class extends Migration
{
    private const TABLES = ['shifts', 'holiday_calendars'];

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
