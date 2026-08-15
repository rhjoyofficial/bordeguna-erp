<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dated exception that makes one specific date a WORKING day again, on a
 * calendar whose weekly-off pattern or dated holiday would otherwise make it
 * non-working. Born 2026-08-15: Friday 7 August was worked, and there was
 * nothing in the model to say so - Fridays come from `weekly_off_days`, a
 * pattern with no per-date escape hatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_day_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_calendar_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason');
            $table->timestamps();

            // One answer per date per calendar - the same shape holidays have.
            $table->unique(['holiday_calendar_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_day_overrides');
    }
};
