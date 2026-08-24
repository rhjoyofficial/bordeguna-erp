<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The durable once-key for "HR was told this person is one late away
         * from a consequence" (Joy, 24 Aug 2026).
         *
         * A row here means the alert for that employee, window and calendar
         * month has been raised. It is a TABLE rather than a flag on
         * daily_attendances because attendance is derived and rebuildable -
         * this project force-rebuilt the whole month twice in one day - and an
         * alert that re-fires on every rebuild teaches HR to ignore the bell.
         * Keyed by employee + window + month, never by daily row id, for the
         * same reason the waiver table is.
         */
        Schema::create('lateness_review_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('grace_window_key', 60);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            // What the count was when the alert fired - the letter HR writes
            // cites it, and the number must not drift if lates keep arriving.
            $table->unsignedInteger('occurrence_number');
            $table->timestamp('alerted_at');
            $table->timestamps();

            // ⚠ Explicit name: the generated one exceeds MySQL's 64 chars.
            $table->unique(
                ['business_id', 'employee_id', 'grace_window_key', 'year', 'month'],
                'lateness_alerts_once_idx',
            );
        });

        /**
         * A show cause notice: the formal letter HR issues after reviewing a
         * lateness alert (or anything else worth one). Immutable once issued -
         * there are no update or delete routes by design; a wrongly issued
         * notice is answered by issuing another statement, the same rule
         * corrections follow.
         */
        Schema::create('show_cause_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 150);
            $table->text('body');
            // Where the notice came from, kept as data so the letter can cite
            // it years later: window key/label, occurrence count, month, the
            // late dates. Nullable - a notice can be issued about anything.
            $table->json('context')->nullable();
            $table->dateTime('issued_at');
            $table->timestamps();

            $table->index(['business_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_cause_notices');
        Schema::dropIfExists('lateness_review_alerts');
    }
};
