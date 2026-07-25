<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An adjustment to a day's attendance, requested and approved rather than
     * applied by hand (COR-01/03/04).
     *
     * This table is what makes ATT-03's immutability workable: a wrong punch is
     * never edited or deleted, a correction is recorded next to it, and the
     * processing pipeline honours the correction when it rebuilds the day. The
     * raw capture and the human judgement about it stay separately auditable.
     *
     * Approval routing lives on the existing multi-stage workflow engine (this
     * model is Approvable), so nothing here reinvents pending/approved state.
     */
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');

            $table->string('type'); // CorrectionType
            // The punch being amended or disregarded. Nullable: an added punch
            // has no original. Kept nullOnDelete only for referential safety —
            // punches are immutable and never deleted in practice.
            $table->foreignId('punch_id')->nullable()->constrained('attendance_punches')->nullOnDelete();
            $table->dateTime('corrected_at')->nullable();
            $table->string('direction')->nullable(); // PunchDirection

            // COR-04: a reason is mandatory. An attachment is optional and uses
            // the Foundation attachment engine rather than a column here.
            $table->text('reason');

            // Correction C1: changing a locked period is not a normal path. When
            // it happens it carries its own stated justification, separate from
            // the correction's own reason.
            $table->string('override_reason')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
