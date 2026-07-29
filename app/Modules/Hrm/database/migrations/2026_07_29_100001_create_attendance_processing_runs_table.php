<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational history for the nightly attendance processor (Correction C15d).
 *
 * Modelled on `leave_year_end_runs`: the job records what it did, so the
 * question "did it run, and what happened" is answered by data rather than by
 * reading logs. The dashboard's freshness tile says attendance is stale; this
 * says why.
 *
 * ⚠ **One row per business per run, never one per employee-day.** The processed
 * days are already the record of themselves; this table exists to answer
 * operational questions and would stop doing so if it grew at the rate of the
 * thing it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_processing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The window this run walked, inclusive.
            $table->date('from_date');
            $table->date('to_date');

            // running → completed | failed. Stored, not derived: a run killed
            // mid-flight (OOM, container restart) leaves `running` behind,
            // which is exactly the state worth being able to see.
            $table->string('status')->default('running');

            $table->unsignedInteger('days_processed')->default(0);
            $table->unsignedInteger('days_finalized')->default(0);
            $table->unsignedInteger('days_skipped_locked')->default(0);
            $table->unsignedInteger('days_skipped_finalized')->default(0);
            $table->unsignedInteger('employees_seen')->default(0);

            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            // Milliseconds, written on completion. Stored rather than computed
            // from the timestamps so a report never has to care that one of
            // them is null on a run that died.
            $table->unsignedBigInteger('duration_ms')->nullable();

            // Failure detail. The class is separate from the message because
            // the class is what you group by when something recurs.
            $table->string('failure_class')->nullable();
            $table->text('failure_message')->nullable();

            // How it was started: 'schedule' or 'manual'. A run somebody
            // triggered by hand while debugging should not look like the
            // nightly job in the history.
            $table->string('trigger')->default('schedule');
            $table->boolean('is_dry_run')->default(false);

            $table->timestamps();

            $table->index(['business_id', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_processing_runs');
    }
};
