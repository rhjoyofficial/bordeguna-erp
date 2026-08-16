<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance policy gains a department scope.
 *
 * Until now one policy governed a whole business, which was true of Bor De Guna
 * until its own circular BdG-HQ-OD-26071901 bound the Retail Sales Department to
 * an 08:15 entry deadline and a show-cause ladder that the office staff it never
 * mentions must not inherit. A department-wide order is the normal shape of this
 * kind of rule, so the scope belongs in the model rather than in a workaround.
 *
 * Null means business-wide, which is what every existing row already is, so the
 * column arrives without changing a single judgement already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->after('business_id')
                ->constrained('departments')
                ->nullOnDelete();

            // The resolution order is "this department, else the business
            // default", and both halves of that read on every processed day.
            $table->index(['business_id', 'department_id', 'effective_from'], 'attendance_policies_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            $table->dropIndex('attendance_policies_scope_index');
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
