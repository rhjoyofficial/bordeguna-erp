<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A named, versioned holiday calendar (SCH-07). Versioning is by `year`, so
     * "Bangladesh 2026" and "Bangladesh 2027" are distinct calendars — history
     * is never rewritten. Carries the weekly off-days (SCH-06; Bangladesh default
     * Friday) as weekday numbers (Carbon dayOfWeek 0=Sun..6=Sat). Assigning a
     * calendar to an office/team/employee is the effective-dated schedule
     * assignment (next 2a slice); this table defines the calendars themselves.
     */
    public function up(): void
    {
        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('year');
            $table->json('weekly_off_days'); // Carbon dayOfWeek numbers, e.g. [5] = Friday
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendars');
    }
};
