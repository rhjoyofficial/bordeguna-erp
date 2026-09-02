<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the column added earlier today. ⚠ The system ALREADY carried the
 * reporting line - employee_assignments.supervisor_id, per posting, written
 * by the transfer flow since 22 July and populated for 8 of 9 employees in
 * production - and the org chart now reads that instead. Two sources of the
 * same fact drift; the assignment-level one is the richer (historical,
 * transfer-aware) and the older, so the newcomer leaves. Verified empty in
 * production before this was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('reports_to')->nullable()->after('user_id')
                ->constrained('employees')->nullOnDelete();
        });
    }
};
