<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One payroll month (PAY-01 via Correction C7b: the 24th through the
         * 23rd). The period is the stable container; a run is an attempt at it,
         * because WFL-06 requires a reopened payroll to produce a new version
         * rather than overwrite the last one.
         */
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            // PAY-02's fixed 30, stored per period so a historical payroll keeps
            // the divisor it was actually calculated on.
            $table->unsignedSmallInteger('divisor_days')->default(30);
            $table->timestamps();

            $table->unique(['business_id', 'year', 'month']);
        });

        /**
         * One calculation attempt. Versioned per WFL-06; the full
         * Draft→Calculated→Reviewed→Approved→Disbursed→Locked lifecycle and its
         * approval arrive in Phase 3c, so this slice keeps only what it needs to
         * calculate and store a result honestly.
         */
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status')->default('draft');
            // PAY-12: the snapshot the run was calculated against, so an
            // approved payroll never silently reflects data that changed after.
            $table->json('config')->nullable();
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->dateTime('calculated_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'version']);
        });

        /**
         * One employee's payslip within a run.
         *
         * `calculation` holds the engine's own derivation — the notes, the
         * config it ran under and the snapshotted facts — because PAY-13
         * requires every amount to remain explainable after the rules move on,
         * and re-deriving it later would give the *current* rules' answer, not
         * the one that was paid.
         */
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('gross_salary', 12, 2)->default(0);
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            // PAY-09 / PAY-11 — a blocked line states plainly why.
            $table->boolean('is_blocked')->default(false);
            $table->json('block_reasons')->nullable();
            $table->json('calculation')->nullable();
            $table->foreignId('salary_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        /**
         * One line of a payslip, carrying its quantity, rate, formula and
         * source (PAY-13). A line that stores only an amount cannot answer the
         * question payroll is asked most often.
         */
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->string('type'); // SalaryComponentType
            $table->decimal('amount', 12, 2);
            $table->decimal('quantity', 10, 4)->nullable();
            $table->decimal('rate', 14, 4)->nullable();
            $table->string('formula');
            $table->string('source');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['payslip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_periods');
    }
};
