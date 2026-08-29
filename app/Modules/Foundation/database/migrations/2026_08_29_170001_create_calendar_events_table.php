<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things somebody types into the calendar (Joy, 29 August 2026).
 *
 * ⚠⚠ THIS TABLE IS THE SMALL HALF OF THE CALENDAR. Most of what the calendar
 * shows is NOT stored here and never will be: holidays, approved leave, CRM
 * follow-ups, sales and purchase orders expected, invoices and bills falling
 * due. Those already exist in their own modules and the calendar READS them.
 *
 * That distinction is the whole design. A calendar that made people re-type
 * what the ERP already knows would be wrong within a week and would then be
 * quietly abandoned, which is exactly the fate of the template pages this was
 * modelled on. What earns a row here is only what has nowhere else to live: a
 * meeting, a visit, a reminder.
 *
 * ⚠ `is_private` hides an event from everybody but the person who wrote it.
 * Without it the calendar is a place nobody records anything personal, and a
 * shared calendar people avoid is worse than none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            // ⚠ Stored as datetimes with an all-day FLAG rather than as two
            // shapes. A nullable time column would make every reader decide for
            // itself what a missing time means, and they would not all decide
            // the same thing.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(true);

            $table->boolean('is_private')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // ⚠ The calendar always asks for a WINDOW, never for one day, so the
            // index is on the range start within a business.
            $table->index(['business_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
