<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The raw punch — the immutable bottom of the attendance stack (ATT-03,
     * explicitly the SAP/Odoo pattern). Nothing in the system ever edits a row
     * in this table: corrections are separate records (Phase 2c) and derived
     * attendance is recalculated from these, never the other way round.
     *
     * `dedup_key` is ATT-08's idempotency key (device + employee + timestamp)
     * hashed into one column, because a plain composite unique index would let
     * duplicates through wherever the device identifier is null — MySQL treats
     * each NULL as distinct. Web/manual punches use their source in the device
     * slot so they dedupe on the same rule.
     *
     * Direction is nullable on purpose (see PunchDirection): pairing is a
     * Phase-2b2 policy decision, not a capture-time guess.
     */
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('punched_at');
            $table->string('source'); // PunchSource
            $table->string('direction')->nullable(); // PunchDirection, null = device gave no usable status
            $table->string('device_identifier')->nullable();
            $table->string('enroll_id')->nullable(); // raw device ID exactly as the export carried it

            // Self-service proof (Correction C2) — always captured for web/mobile.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_meters', 8, 2)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('device_metadata')->nullable();

            // Capture-time facts, never resolved in place — clearing a flag would
            // be an edit. Reviewing an exception is a Phase-2c correction record.
            $table->boolean('is_outside_geofence')->default(false);
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();

            $table->string('dedup_key', 64);
            $table->string('note')->nullable(); // required for manual entry (ATT-01 audit)
            $table->foreignId('import_batch_id')->nullable()
                ->constrained('attendance_import_batches')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'dedup_key']);
            $table->index(['business_id', 'employee_id', 'punched_at']);
            $table->index(['business_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
