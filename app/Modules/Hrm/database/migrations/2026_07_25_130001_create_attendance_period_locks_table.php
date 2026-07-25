<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A closed attendance period (COR-05: daily processing is provisional, the
     * period locks weekly and at payroll cutoff).
     *
     * This is the deadline COR-02 hangs on — "before payroll cutoff" only means
     * something once a period can actually be closed — and the boundary
     * Correction C1 guards: once locked, a change is not a normal edit but an
     * audited emergency override with a mandatory reason.
     *
     * Deliberately generic rather than payroll-specific. Phase 3 locks the same
     * period at cutoff, and SCH-05's "no retroactive roster change past a
     * payroll lock" finally has something concrete to test against.
     */
    public function up(): void
    {
        Schema::create('attendance_period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('note')->nullable();
            $table->dateTime('locked_at');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            // Unlocking is itself an audited event rather than a delete, so a
            // reopened period leaves a trail of who reopened it and why.
            $table->dateTime('unlocked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unlock_reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_period_locks');
    }
};
