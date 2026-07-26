<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * A loan or salary advance (ADJ-07, which ticked "সবগুলো" — every option).
         *
         * Three tables, and the split is the whole design:
         *
         *  - **`loans`** is the *agreement*: what was borrowed and on what terms.
         *  - **`loan_installments`** is the *plan*: what is expected to happen,
         *    month by month.
         *  - **`loan_ledger_entries`** is the *fact*: what actually happened.
         *
         * Keeping the plan and the fact apart is what lets a schedule be
         * re-planned without rewriting history, and what makes the outstanding
         * balance a sum of events rather than a number someone maintains. The
         * leave ledger earned this shape in Phase 2c and the PAY-11 balance
         * ledger repeated it in 3d; a loan is the case where it matters most,
         * because the number is money the employee actually owes.
         *
         * Note what is deliberately absent: **no interest, no fees, no penalty
         * rate.** ADJ-07 lists principal, installment, start month and remaining
         * balance, and nothing else. An interest rate would be invented.
         */
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            // ADJ-07 names both in one breath ("Loan/Salary Advance"), and they
            // behave identically — only the label differs on a payslip.
            $table->string('type')->default('loan'); // LoanType
            $table->string('status')->default('pending'); // LoanStatus
            $table->decimal('principal', 12, 2);
            $table->decimal('installment_amount', 12, 2);
            $table->unsignedSmallInteger('installment_count')->default(0);
            // ADJ-07's "start month". Held as year/month rather than a period
            // foreign key so scheduling a loan does not silently create a dozen
            // future payroll periods as a side effect.
            $table->unsignedSmallInteger('start_year');
            $table->unsignedTinyInteger('start_month');
            $table->text('reason');
            $table->date('disbursed_on')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->string('settlement_note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['employee_id', 'status']);
        });

        /**
         * The repayment plan — one row per expected installment.
         *
         * This is a *plan*, not a record of payment. It gives each deduction a
         * stable installment number ("3 of 12") that survives a re-planned
         * schedule, and it is what payroll looks up to find what is due this
         * month. Whether it was actually paid is answered by the ledger, and the
         * link between the two is `payslip_id`: set only once real money moved.
         */
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            // Matched to a payroll period by year and month, so an installment
            // can be scheduled into a month whose period does not exist yet.
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('scheduled'); // LoanInstallmentStatus
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'sequence']);
            $table->index(['loan_id', 'year', 'month']);
        });

        /**
         * Every movement of a loan balance, append-only.
         *
         * The outstanding balance is the sum of these rows and is never stored,
         * so it cannot drift from the events that explain it and every figure on
         * a payslip can be traced back to the disbursement it repays.
         *
         * `source_key` carries the idempotency that matters most here: a payroll
         * run may be recalculated freely under WFL-05, and a repayment posted
         * twice would take the same money from an employee twice.
         */
        Schema::create('loan_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type'); // LoanLedgerEntryType
            // Signed: a disbursement is positive (the employee owes it), every
            // repayment and settlement negative.
            $table->decimal('amount', 12, 2);
            $table->date('occurred_on');
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->nullableMorphs('reference');
            $table->string('source_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'source_key']);
            $table->index(['loan_id', 'occurred_on']);
            $table->index(['employee_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_ledger_entries');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
    }
};
