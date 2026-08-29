<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes (Joy, 29 August 2026).
 *
 * ⚠⚠ THE HONEST RISK, WRITTEN DOWN. On 27 August the advice against building
 * this was that a notes page photographs well in a template and then rots
 * unused, taxing every future upgrade. Joy has since asked for it, so it is
 * built - but the reason it might rot is real and the schema answers it in one
 * column: `remind_on`. A note nobody ever reads again is worthless, and the
 * cheapest thing that makes one resurface is a date on which it appears on the
 * calendar people already look at. Without that this is a drawer nothing ever
 * comes back out of.
 *
 * ⚠ `is_private` is the default OFF, not on. A shared-by-default notes board is
 * the thing a nine-person business actually wants; private is the exception you
 * reach for, and the field exists so people are willing to write anything at all.
 *
 * ⚠⚠ ARCHIVED, NOT DELETED. `archived_at` rather than a hard delete, because
 * the one thing people ask of a notes system months later is "what was that
 * thing we wrote down", and a delete button is how that answer disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('body')->nullable();

            $table->boolean('is_private')->default(false);
            $table->boolean('is_pinned')->default(false);

            // ⚠ A DATE, not a datetime. This is "bring this back to me that day",
            // not an appointment; the calendar draws it on the day and nothing
            // here pretends to know what time somebody meant.
            $table->date('remind_on')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // The board is read pinned-first, unarchived, newest first.
            $table->index(['business_id', 'archived_at', 'is_pinned']);
            // The calendar asks for a window of reminder dates.
            $table->index(['business_id', 'remind_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
