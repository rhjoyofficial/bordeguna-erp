<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The reusable salary components a business pays and deducts
         * (SAL-02 earnings, SAL-03 deductions, SAL-04 calculation basis).
         *
         * Definitions live here once; an employee's structure references them
         * and supplies only the figure. That is what stops "House Rent" being
         * spelled three ways across three employees and makes a payslip line
         * traceable to a definition rather than to free text.
         */
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type'); // SalaryComponentType
            // Exactly one component per business carries this. It is the base
            // that percentage components are measured against.
            $table->boolean('is_basic')->default(false);
            $table->string('calculation_basis')->default('fixed'); // SalaryCalculationBasis
            // Ordering on the payslip, so earnings and deductions read in a
            // stable, human-chosen sequence rather than by insertion order.
            $table->unsignedSmallInteger('sequence')->default(0);
            // Whether payroll may compute this itself. Income Tax and PF are
            // named in SAL-03 but GOV-06 (statutory automation scope) is still
            // unresolved, so those ship as manually-entered amounts rather than
            // with an invented formula.
            $table->boolean('is_statutory')->default(false);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        /**
         * One employee's salary, effective-dated (SAL-05, P1).
         *
         * SAL-06 allows only one active structure at a time, and Correction C7c
         * settles which business owns it: the one holding the employee's active
         * primary assignment. A revision closes the current row and opens a new
         * one rather than overwriting — the same close-old-open-new pattern
         * schedules and assignments use — so "what were they paid in March" stays
         * answerable after any number of increments.
         */
        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Why this revision happened — an increment, a correction, the
            // opening structure. Shown on the history so a change is never
            // just a pair of numbers.
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'effective_from']);
        });

        /**
         * The figures for one structure. `value` is an amount for a fixed
         * component and a percentage for the other bases — the component's own
         * basis says which, so the meaning is never ambiguous.
         */
        Schema::create('salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained()->cascadeOnDelete();
            // 4 decimal places, not 2: this column holds an amount for a fixed
            // component but a *percentage* for the others, and a rate like
            // 33.333% would silently truncate at 2. The resolved money amount
            // is still rounded to 2 decimals per PAY-10.
            $table->decimal('value', 14, 4);
            $table->timestamps();

            // ⚠ Explicit name: generated is 69 chars, MySQL's limit is 64. The
            // CONSTRAINT is load-bearing (one line per component per structure);
            // only the name changes.
            $table->unique(['salary_structure_id', 'salary_component_id'], 'salary_structure_lines_structure_component_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_structure_lines');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('salary_components');
    }
};
