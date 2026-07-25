<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Binds a shift + holiday calendar to a group or a single employee for a
     * period of time (SCH-01/06/07). Effective-dated like every other temporal
     * HRM record: a change closes the old row and opens a new one, so a
     * temporary schedule (SCH-08, e.g. Ramadan hours) is expressed as new rows
     * with a date range and never as an edit to schedule history.
     *
     * `scope_type` + `scope_id` form a precedence ladder (business < office <
     * department < section < employee) resolved by ScheduleResolver — the most
     * specific row in effect on a date wins, so a new hire inherits their
     * office/department schedule with no backfill.
     */
    public function up(): void
    {
        Schema::create('schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type'); // ScheduleScope
            $table->unsignedBigInteger('scope_id')->nullable(); // null only for the business-wide scope
            $table->string('schedule_type'); // ScheduleType (SCH-01)
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holiday_calendar_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Deliberately stored rather than inferred from `effective_to`: a row
            // also gets an end date when a later assignment closes it, and a
            // closed schedule is not the same thing as a temporary one (SCH-08).
            $table->boolean('is_temporary')->default(false);
            // Provenance of the follow-on row an ending temporary schedule opens,
            // so an auto-restored schedule is distinguishable from a hand-made one.
            $table->foreignId('restores_assignment_id')->nullable()
                ->constrained('schedule_assignments')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'scope_type', 'scope_id']);
            $table->index(['business_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_assignments');
    }
};
