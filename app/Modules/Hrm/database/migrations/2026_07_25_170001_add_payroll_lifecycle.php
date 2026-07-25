<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // WFL-03: the pre-calculation report, kept on the run so the errors
            // HR was shown are auditable rather than a transient flash message.
            $table->json('validation_report')->nullable()->after('config');
            $table->boolean('is_validated')->default(false)->after('validation_report');

            // WFL-02's lifecycle stamps. Each transition records who and when,
            // because a payroll's history is the first thing an auditor asks for.
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();

            // WFL-02's Rejected and Cancelled states carry their reason.
            $table->string('closed_reason')->nullable();
            // WFL-06: which version this one superseded, so a reopened payroll
            // reads as a chain rather than a set of unrelated attempts.
            $table->foreignId('reopened_from_id')->nullable()->constrained('payroll_runs')->nullOnDelete();

            // WFL-04: a queued calculation reports progress; the job's own
            // failure message lands here so a failed run explains itself.
            $table->string('queue_state')->nullable();
            $table->text('failure_message')->nullable();
        });

        /**
         * WFL-08: unlocking a locked payroll needs HR Head, Finance and
         * Management approval, and a Super Admin emergency override with a
         * mandatory reason and full audit.
         *
         * Its own approvable record rather than a status flag, because the
         * request carries a justification and three separate decisions — which
         * is exactly what the existing multi-stage workflow engine models.
         */
        Schema::create('payroll_unlock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            // The Super Admin emergency path of WFL-08 — allowed, but never
            // silent: it records that it bypassed the chain and why.
            $table->boolean('is_emergency_override')->default(false);
            $table->string('override_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_unlock_requests');

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropConstrainedForeignId('locked_by');
            $table->dropConstrainedForeignId('reopened_from_id');
            $table->dropColumn([
                'validation_report', 'is_validated', 'reviewed_at', 'approved_at',
                'disbursed_at', 'locked_at', 'closed_reason', 'queue_state', 'failure_message',
            ]);
        });
    }
};
