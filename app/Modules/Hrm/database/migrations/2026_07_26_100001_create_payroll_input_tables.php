<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * ADJ-01 through Correction C8d: eligibility is a flag HR sets, not an
         * invented list of grades.
         *
         * ADJ-01 ticked "specific employment type/grade" *and* "only approved
         * overtime assignment", but never named a single type or grade. Rather
         * than fabricate the category, eligibility lives on the effective-dated
         * salary structure — so a promotion that changes it is dated like every
         * other salary change, and a historical payroll recalculation uses the
         * eligibility that was actually in force at the time.
         */
        Schema::table('salary_structures', function (Blueprint $table) {
            $table->boolean('overtime_eligible')->default(false)->after('effective_to');
        });

        /**
         * An authorised stint of overtime (ADJ-01, ADJ-03, ADJ-04).
         *
         * Hours are *authorised*, never derived from punches — ADJ-01 ticked
         * "only approved overtime assignment", and deriving them would mean
         * reopening the attendance engine, which Phase 3 is explicitly barred
         * from doing.
         *
         * One row per employee per date, so ADJ-03's daily four-hour ceiling has
         * something to be enforced against; the monthly ceiling stays with the
         * engine, which is the only place that sees a whole period at once.
         */
        Schema::create('overtime_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 6, 2);
            /**
             * ADJ-04 / Correction C8b. A holiday or off-day stint is compensated
             * in leave, not money, so this flag decides whether the hours reach
             * payroll at all — they are either paid or credited, never both.
             */
            $table->boolean('is_holiday_work')->default(false);
            // The compensatory days actually credited, stored rather than
            // re-derived: the standard-hours divisor behind it is configuration
            // and may change, but what someone was granted must not.
            $table->decimal('compensatory_days', 6, 2)->default(0);
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['business_id', 'work_date']);
        });

        /**
         * ADJ-05: "bonus event অনুযায়ী configurable" — the only ticked option.
         *
         * So a bonus is not a standing rule but an occasion: Eid, a festival, a
         * performance cycle. The event carries the rule (a fixed amount, or a
         * share of Basic or Gross) and the payroll month it lands in; the awards
         * beneath it carry who receives it and the resolved figure.
         */
        Schema::create('bonus_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Reuses SalaryCalculationBasis rather than a parallel enum: "fixed
            // amount or a percentage of Basic or Gross" is the same question
            // SAL-04 already answered for components.
            $table->string('calculation_basis')->default('fixed');
            $table->decimal('value', 14, 4);
            // ADJ-09's "effective payroll month" applied to a bonus too — a
            // bonus without a month has no payslip to land on.
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'payroll_period_id']);
        });

        /**
         * One employee's share of a bonus event. Approvable in its own right, so
         * a single employee can be turned down without unpicking the event.
         */
        Schema::create('bonus_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bonus_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // Resolved at award time against the salary then in force, and
            // stored: a later increment must not retroactively change what a
            // festival bonus was worth.
            $table->decimal('amount', 12, 2);
            $table->string('basis_note')->nullable();
            $table->timestamps();

            $table->unique(['bonus_event_id', 'employee_id']);
        });

        /**
         * A manual money movement (ADJ-08, ADJ-09).
         *
         * ADJ-08 ticked all six types, and each keeps its own line on the
         * payslip: "one adjustment of −3,200" answers nobody's question, which
         * is precisely why six types were named.
         *
         * ADJ-09 ticked all four controls, so reason, attachment, approval and
         * effective payroll month are every one of them mandatory. Three are
         * columns here; the attachment is enforced at the point of submission,
         * because the Attachment engine stores polymorphically.
         */
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // PayrollAdjustmentType
            // Always positive. The type decides which side of the payslip it
            // falls on, so a stray minus sign can never quietly turn a penalty
            // into a payment.
            $table->decimal('amount', 12, 2);
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            // Set for the arrears SAL-05 generates, so an increment's forward
            // correction can be traced back to the revision that caused it.
            $table->nullableMorphs('origin');
            // Idempotency for anything generated rather than typed — the same
            // guarantee `source_key` gives the leave ledger.
            $table->string('source_key')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'source_key']);
            $table->index(['employee_id', 'payroll_period_id']);
        });

        /**
         * An increment proposal (ADJ-06).
         *
         * ADJ-06 ticked "Proposal → Approval → Effective salary revision", so a
         * revision is a *request* first: the proposed figures sit here until
         * approved, and only then does `SaveSalaryStructure` open the new
         * effective-dated structure. HR cannot quietly raise a salary by editing
         * a row.
         *
         * SAL-05 allows a retroactive effective date, and its recommendation is
         * explicit that the difference becomes an arrear. So approval may also
         * emit `payroll_adjustments` rows — never a rewrite of a closed payslip.
         */
        Schema::create('salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            // The proposed structure, held as data until approval. Not written
            // to salary_structures first and corrected later, because a
            // structure that exists is a structure payroll would pay.
            $table->json('proposed_lines');
            $table->boolean('overtime_eligible')->default(false);
            $table->text('reason');
            // Filled on approval, so a revision points at what it produced.
            $table->foreignId('salary_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('arrear_amount', 12, 2)->default(0);
            $table->json('arrear_breakdown')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'employee_id', 'effective_from']);
        });

        /**
         * PAY-11's carry-forward balance, per Correction C8c.
         *
         * PAY-11 ticked "approval block + manual resolution + carry-forward
         * balance" but never said how the balance is recovered. C8c settled it:
         * the shortfall carries forward and is recovered in full from the next
         * payroll; if that month also goes short, it simply rolls again.
         *
         * Append-only, exactly like the leave ledger — an outstanding balance is
         * the sum of these rows and never a stored number, so it can always be
         * explained back to the payslips that produced it.
         */
        Schema::create('payroll_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type'); // PayrollBalanceEntryType
            // Signed: a carry-forward is positive (owed to the company), a
            // recovery negative. The outstanding balance is the running sum.
            $table->decimal('amount', 12, 2);
            $table->foreignId('payroll_period_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->nullableMorphs('reference');
            $table->string('source_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'source_key']);
            $table->index(['employee_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_balance_entries');
        Schema::dropIfExists('salary_revisions');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('bonus_awards');
        Schema::dropIfExists('bonus_events');
        Schema::dropIfExists('overtime_assignments');

        Schema::table('salary_structures', function (Blueprint $table) {
            $table->dropColumn('overtime_eligible');
        });
    }
};
