<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Popups and sounds (Joy, 14 Oct 2026): a sound library the Owner manages, the
 * map from each sound event to a library sound, the popups themselves, and the
 * record that a once-a-day or once-per-person popup was shown.
 *
 * ⚠ GROUP-WIDE, like failed jobs: no business_id. One Bismillah for everybody,
 * one set of sounds. Role audiences are stored as role NAMES and matched against
 * the person's roles in the business they are working in (roles are per business).
 *
 * ⚠ Files live on the private `local` disk and are streamed by a controller to
 * signed-in people only; the table holds the path and the facts, never the bytes.
 *
 * The built-in Bismillah popup is seeded here with the text, timing and close
 * time it had since 11 Oct, so nobody sees a different greeting on deploy day.
 * It is found by its permanent key, never by its id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sounds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('disk', 20)->default('local');
            $table->string('path');
            // The type chosen from the allowlist at upload, served as is (never re-sniffed).
            $table->string('mime_type', 40);
            $table->unsignedInteger('size');
            // Read by the Owner's browser at upload; for display, not a security check.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sound_slots', function (Blueprint $table) {
            // success | attention | error | popup
            $table->string('slot', 20)->primary();
            // Null = the built-in sound (public/sounds) or, for popup, silence.
            $table->foreignId('sound_id')->nullable()->constrained('sounds')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('popups', function (Blueprint $table) {
            $table->id();
            // Built-in popups only ('bismillah'): editable and pausable, never deleted.
            $table->string('key', 40)->nullable()->unique();
            $table->string('title', 120);
            // [{kind: arabic|heading|body|note, text}] - plain text, rendered as text.
            $table->json('lines');
            $table->string('style', 20)->default('calm');
            $table->boolean('on_login')->default(false);
            $table->unsignedSmallInteger('idle_minutes')->nullable();
            // Null = everybody; otherwise role names.
            $table->json('audience_roles')->nullable();
            // every | daily | once
            $table->string('frequency', 10)->default('every');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // default (the popup event's sound) | none | custom (sound_id)
            $table->string('sound_mode', 10)->default('default');
            $table->foreignId('sound_id')->nullable()->constrained('sounds')->restrictOnDelete();
            // Null = stays until the person closes it.
            $table->unsignedSmallInteger('close_after_seconds')->nullable();
            // Higher shows first.
            $table->unsignedSmallInteger('priority')->default(50);
            $table->boolean('is_active')->default(true);
            // none | video | youtube
            $table->string('media_type', 10)->default('none');
            $table->string('media_path')->nullable();
            $table->string('media_mime', 40)->nullable();
            $table->unsignedInteger('media_size')->nullable();
            $table->string('youtube_id', 11)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('popup_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('popup_id')->constrained('popups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // 'once', or the Dhaka date (Y-m-d) for a once-a-day popup.
            $table->string('period', 10);
            $table->timestamp('shown_at');
            // ⚠ The claim is this unique key: two tabs or two devices racing for
            // the same once-only popup, and only one insert wins.
            $table->unique(['popup_id', 'user_id', 'period']);
        });

        $now = now();

        foreach (['success', 'attention', 'error', 'popup'] as $slot) {
            DB::table('sound_slots')->insert(['slot' => $slot, 'sound_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }

        DB::table('popups')->insert([
            'key' => 'bismillah',
            'title' => 'Bismillah',
            'lines' => json_encode([
                ['kind' => 'arabic', 'text' => 'بِسْمِ ٱللَّٰهِ ٱلرَّحْمَٰنِ ٱلرَّحِيمِ'],
                ['kind' => 'heading', 'text' => 'বিসমিল্লাহির রাহমানির রাহিম'],
                ['kind' => 'note', 'text' => 'পরম করুণাময়, অতি দয়ালু আল্লাহর নামে'],
            ], JSON_UNESCAPED_UNICODE),
            'style' => 'bismillah',
            'on_login' => true,
            'idle_minutes' => 60,
            'audience_roles' => null,
            'frequency' => 'every',
            'sound_mode' => 'default',
            'close_after_seconds' => 6,
            'priority' => 100,
            'is_active' => true,
            'media_type' => 'none',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('popup_views');
        Schema::dropIfExists('popups');
        Schema::dropIfExists('sound_slots');
        Schema::dropIfExists('sounds');
    }
};
