<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1, Slice 1 (master plan, 2 Sep 2026): the reporting hierarchy.
 *
 * One nullable self-reference is the entire schema of "who reports to whom".
 * Null means the person answers to nobody inside the system - the founder,
 * and anyone whose chain has not been recorded yet. nullOnDelete: removing
 * an employee must orphan their reports gracefully, never cascade or block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('reports_to')->nullable()->after('user_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to');
        });
    }
};
