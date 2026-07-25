<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One processed day per employee — the payroll-ready result.
     *
     * Unlike a raw punch this row is *derived* and may be recalculated (ATT-03
     * draws the line exactly there: the punch never changes, the processed
     * attendance can be recomputed). Reprocessing replaces the row.
     *
     * `policy_id` + `policy_version` + `calculation` are the provenance: which
     * rules produced this, and the engine's own reasoning for every figure. A
     * payslip line has to be explainable years later without re-running
     * anything, and after a policy revision the original rules may no longer be
     * the current ones.
     *
     * `is_provisional` carries POL-06: the system proposes, a human finalises.
     */
    public function up(): void
    {
        Schema::create('daily_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');

            $table->string('status');
            $table->string('consequence')->default('none');

            $table->dateTime('first_in')->nullable();
            $table->dateTime('last_out')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('payable_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);

            $table->string('grace_window_key')->nullable();
            $table->unsignedInteger('occurrence_number')->nullable();

            $table->boolean('is_provisional')->default(true);
            $table->boolean('holds_payroll')->default(false);
            $table->json('flags')->nullable();
            $table->json('calculation')->nullable();

            $table->foreignId('policy_id')->nullable()->constrained('attendance_policies')->nullOnDelete();
            $table->unsignedInteger('policy_version')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'employee_id', 'attendance_date']);
            $table->index(['business_id', 'attendance_date']);
            // Drives the monthly occurrence counters in POL-03.
            $table->index(['employee_id', 'grace_window_key', 'attendance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendances');
    }
};
