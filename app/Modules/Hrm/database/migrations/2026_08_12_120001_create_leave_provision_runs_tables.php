<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave encashment provision: the liability that exists today and is
     * recorded nowhere (coverage-matrix item 5, LEAVE-PROVISION-AND-
     * ENCASHMENT-ARCHITECTURE.md).
     *
     * ⚠⚠ A TRUE-UP RUN, NOT AN ACCRUAL STREAM. Every leave event already lives
     * in the leave ledger; mirroring each grant/taken/lapse into the accounting
     * ledger would be a second event stream to keep in step - the duplicate
     * path this project has twice collapsed. The run computes the whole
     * liability and posts only the DELTA, so a skipped month, a raise or a data
     * correction all self-heal on the next run.
     *
     * ⚠ Plan-equals-commit, the LeaveYearEndRun shape: `plan()` is the only
     * place a decision is made and `commit()` writes exactly what it returned.
     */
    public function up(): void
    {
        Schema::create('leave_provision_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The valuation date. ⚠ A DATE, not a period: the provision is
            // always "as of now" and prior months are never restated (§6).
            $table->date('valued_on');

            $table->boolean('is_dry_run')->default(false);

            // ⚠ The per-employee rows exactly as computed - days, the PAY-02
            // rate they were priced at, and the resulting liability. A figure
            // that cannot explain itself is what this platform exists to avoid,
            // and "why did the provision move" is the question this answers.
            $table->json('plan');

            $table->integer('employees_affected')->default(0);

            // The whole computed liability, the balance it was compared with,
            // and the delta posted. Storing all three makes the journal
            // re-derivable from the document without recomputing history.
            $table->decimal('total_liability', 15, 4)->default(0);
            $table->decimal('opening_balance', 15, 4)->default(0);
            $table->decimal('delta', 15, 4)->default(0);

            $table->text('note')->nullable();
            $table->foreignId('run_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'valued_on'], 'leave_provision_runs_business_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_provision_runs');
    }
};
