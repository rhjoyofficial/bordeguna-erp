<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reusable shift definition (SCH-02) — the fixed-hours template an
     * employee's schedule references. Holds the raw timing configuration
     * (start/end, grace, break, minimum payable hours, late/early thresholds,
     * overnight handling); the Phase-2b attendance engine interprets these
     * against real punches. Business-scoped and named; temporary schedules
     * (Ramadan, SCH-08) are separate shifts assigned for an effective date
     * range, never edits to an existing one.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes')->default(15);        // late-arrival grace (C4/POL-01)
            $table->unsignedSmallInteger('break_minutes')->default(0);         // break duration
            $table->decimal('minimum_payable_hours', 4, 2)->nullable();        // min hours to count as payable
            $table->unsignedSmallInteger('late_threshold_minutes')->nullable();       // past start+this = flagged late
            $table->unsignedSmallInteger('early_leave_threshold_minutes')->nullable(); // more than this before end = flagged early
            $table->boolean('is_overnight')->default(false);                   // shift crosses midnight (SCH-03)
            $table->unsignedSmallInteger('cross_midnight_window_minutes')->nullable(); // window after midnight still counted on start date
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
