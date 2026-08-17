<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The meal-window configuration (MEAL-BREAK-ARCHITECTURE.md, Part 5).
     *
     * The shift is already the home of break treatment (POL-08), which is what
     * delivers "Office only, not Retail" with no new scoping concept: the mode
     * is switched per shift via `break_policy`, and these columns only carry
     * meaning when it is `meal_window`.
     *
     * All nullable so every existing row is untouched — the feature ships built
     * but OFF, and the enabling act is a deliberate, dated shift config change
     * (decision D5, Joy 2026-08-17).
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->time('meal_window_start')->nullable()->after('break_policy');
            $table->time('meal_window_end')->nullable()->after('meal_window_start');
            $table->unsignedInteger('meal_standard_minutes')->nullable()->after('meal_window_end');
            $table->unsignedInteger('meal_allowance_minutes')->nullable()->after('meal_standard_minutes');
            $table->boolean('meal_deduct_when_not_punched')->default(true)->after('meal_allowance_minutes');
            $table->unsignedInteger('meal_short_day_tolerance_minutes')->default(5)->after('meal_deduct_when_not_punched');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'meal_window_start',
                'meal_window_end',
                'meal_standard_minutes',
                'meal_allowance_minutes',
                'meal_deduct_when_not_punched',
                'meal_short_day_tolerance_minutes',
            ]);
        });
    }
};
