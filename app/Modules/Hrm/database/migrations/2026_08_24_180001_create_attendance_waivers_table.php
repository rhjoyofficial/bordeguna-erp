<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A named person forgiving one day's salary consequence (Joy, 24 Aug 2026:
     * "permission person can forgive this kind of things which recalculate the
     * deduction").
     *
     * A separate table, deliberately NOT columns on daily_attendances: the
     * day's computed truth stays exactly what the engine wrote, and
     * reprocessing - which rewrites that row in place - can never erase a
     * human's act of forgiveness.
     *
     * ⚠ Keyed by (business, employee, date), NOT by daily_attendance_id.
     * ProcessAttendance updates rows in place today, so ids happen to be
     * stable - but this project has rebuilt attendance from raw punches twice
     * in one day, and a rebuild that recreates rows would orphan an id-keyed
     * waiver. attendance_corrections set the precedent: reference the DAY,
     * which survives anything short of the calendar changing.
     *
     * ⚠ No unique index: an undone waiver stays as history (revoked_at), and
     * MySQL has no partial unique index to say "unique while active". The
     * one-active-waiver-per-day rule is enforced in the controller and pinned
     * by test.
     */
    public function up(): void
    {
        Schema::create('attendance_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');

            // What was being forgiven, captured at waive time - the day may be
            // reprocessed into something else later, and the trail should say
            // what the forgiver actually saw.
            $table->string('waived_status');
            $table->string('waived_consequence');

            // A waiver moves money, so the reason is required, not polite.
            $table->text('reason');
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();

            // Undo keeps the history: a revoked waiver is dead but on record.
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // ⚠ Explicit name: the generated one exceeds MySQL's 64-char limit,
            // the same lesson attendance_corrections carries.
            $table->index(['business_id', 'employee_id', 'attendance_date'], 'att_waivers_business_emp_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_waivers');
    }
};
