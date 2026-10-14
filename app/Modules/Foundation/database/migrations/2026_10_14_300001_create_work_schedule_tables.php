<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work schedule alerts (Joy, 14 Oct 2026, "Deep Focus Time Policy"): the
 * slots of the working day, and one row of group-wide settings (how long an
 * alert stays, and the master switch). When a slot starts, every open ERP tab
 * shows a short alert with the slot's title and, if the person allows it, a
 * sound.
 *
 * GROUP-WIDE, like the popups: no business_id. One rhythm for the whole team.
 * Weekdays are 0 = Sunday .. 6 = Saturday; Saturday to Thursday by default
 * (Friday off). Times are Asia/Dhaka wall-clock times.
 *
 * Also adds the 'schedule' sound event, null = the built-in success sound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('title', 80);
            $table->string('subtitle', 160)->nullable();
            // focus | break | meal | prayer | wrap
            $table->string('kind', 10)->default('focus');
            // [6,0,1,2,3,4] = Saturday to Thursday.
            $table->json('weekdays');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ⚠ One row (id 1). There is no group-wide settings store to put
        // these two values in, so they get this small table.
        Schema::create('work_schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('alert_seconds')->default(8);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('work_schedule_settings')->insert(['id' => 1, 'alert_seconds' => 8, 'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

        $days = json_encode([6, 0, 1, 2, 3, 4]);
        $slots = [
            ['09:05', '11:00', '1st Deep Focus Slot', 'Focused time for high-priority tasks.', 'focus'],
            ['11:00', '11:10', 'Tea / Rest Break', 'Short Mental Refresh', 'break'],
            ['11:10', '12:45', '2nd Deep Focus Slot', 'Deep focus and analytical work', 'focus'],
            ['12:45', '13:29', 'Lunch, Prayer & Nap', 'Lunch break and prayer time', 'meal'],
            ['13:30', '15:00', '3rd Deep Focus Slot', 'Creative or strategic work time', 'focus'],
            ['15:00', '15:10', 'Tea / Rest Break', 'Short Mental Refresh', 'break'],
            ['15:10', '16:40', '4th Deep Focus Slot', 'Team-oriented project focus', 'focus'],
            ['16:40', '17:00', 'Prayer', 'Asr prayer', 'prayer'],
            ['17:00', '18:00', '5th Semi-deep Focus Slot', 'reviews, reports, wrap up', 'wrap'],
        ];

        foreach ($slots as $index => [$start, $end, $title, $subtitle, $kind]) {
            DB::table('work_schedule_slots')->insert([
                'starts_at' => $start.':00',
                'ends_at' => $end.':00',
                'title' => $title,
                'subtitle' => $subtitle,
                'kind' => $kind,
                'weekdays' => $days,
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('sound_slots') && ! DB::table('sound_slots')->where('slot', 'schedule')->exists()) {
            DB::table('sound_slots')->insert(['slot' => 'schedule', 'sound_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sound_slots')) {
            DB::table('sound_slots')->where('slot', 'schedule')->delete();
        }

        Schema::dropIfExists('work_schedule_settings');
        Schema::dropIfExists('work_schedule_slots');
    }
};
