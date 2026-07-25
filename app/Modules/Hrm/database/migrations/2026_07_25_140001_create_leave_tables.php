<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The leave types of LEV-01, each carrying its own rules.
         *
         * Correction C6c put carry-forward and encashment on the *type* rather
         * than applying one blanket rule: carrying forward and paying out
         * maternity or sick leave is not what LEV-03 meant, even though its
         * literal answer said so. C6b's cap lives here too, so HR revises it
         * without a release.
         */
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            // LEV-02: the full quota granted at the start of each calendar year.
            $table->decimal('annual_quota', 6, 2)->default(0);
            $table->boolean('is_paid')->default(true);
            // Unpaid leave is recorded but draws on no balance (LEV-07's target).
            $table->boolean('deducts_balance')->default(true);
            $table->boolean('allows_half_day')->default(true);
            // Correction C6c.
            $table->boolean('can_carry_forward')->default(false);
            $table->boolean('can_encash')->default(false);
            // Correction C6b — null means "twice the annual quota".
            $table->decimal('carry_forward_cap', 6, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        /**
         * The append-only balance ledger. A balance is the sum of these rows,
         * never a stored number — so every figure is explainable and the
         * year-end job is safe to rerun.
         *
         * `source_key` is what makes that true: an operation that must happen
         * once per employee/type/year writes a deterministic key, and a rerun
         * finds it already present instead of double-posting.
         */
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('entry_type'); // LeaveLedgerEntryType
            // Signed: credits positive, taken/lapse/encashment negative.
            $table->decimal('days', 8, 2);
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->nullableMorphs('reference'); // the leave request, year-end run, ...
            $table->string('source_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'source_key']);
            $table->index(['employee_id', 'leave_type_id', 'year']);
        });

        /**
         * A leave request (LEV-04/05/06/07). Approvable, so routing rides on the
         * existing workflow engine.
         */
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('day_portion')->default('full_day'); // LeaveDayPortion
            // LEV-06: holidays and weekly offs inside the range are excluded, so
            // the chargeable total is stored rather than inferred from the dates.
            $table->decimal('days', 6, 2);
            // LEV-07: the part with no balance behind it, converted to unpaid.
            $table->decimal('unpaid_days', 6, 2)->default(0);
            $table->text('reason');
            $table->json('covered_dates')->nullable(); // the working days actually charged
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'start_date']);
        });

        /**
         * Approved presence outside the normal pattern (POL-09 / POL-10).
         */
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // MovementType
            $table->date('movement_date');
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->string('place')->nullable();
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'movement_date']);
        });

        /**
         * One record per year-end run (Correction C6b/C6d), storing the plan it
         * acted on. A preview writes one of these too, marked as a dry run, so
         * "what would happen" is itself auditable and can be shown to HR before
         * anything is committed.
         */
        Schema::create('leave_year_end_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->boolean('is_dry_run')->default(true);
            $table->json('plan')->nullable();
            $table->unsignedInteger('employees_affected')->default(0);
            $table->decimal('total_carried', 10, 2)->default(0);
            $table->decimal('total_lapsed', 10, 2)->default(0);
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_year_end_runs');
        Schema::dropIfExists('movements');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('leave_types');
    }
};
