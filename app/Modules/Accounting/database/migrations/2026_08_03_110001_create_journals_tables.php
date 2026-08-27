<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2.2 of Accounting: the journal, its lines, and the sequence that
     * numbers them.
     *
     * A journal is a dated, balanced, immutable set of lines that points back at
     * whatever caused it. That is the whole of double-entry bookkeeping, and it
     * is the same shape this ERP already uses for attendance punches and stock
     * movements, one level up.
     */
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Human-facing and permanent in printed records (C-LGR-2).
            $table->string('journal_number', 30);

            // ⚠⚠ THE ACCOUNTING DATE, and a `date` rather than a `datetime`.
            // Exactly the decision stock_movements.occurred_at made, which the
            // boundary review called the most important thing the Inventory
            // engine got right and the most painful to retrofit. `created_at`
            // records when we were TOLD; this records when it HAPPENED, and only
            // this one may be backdated.
            $table->date('journal_date');

            $table->text('memo')->nullable();

            // ⚠ Every journal points back at its cause. NULL for a manual
            // journal, which is the only kind that exists in Phase 2 - from
            // Phase 3 this is the payslip, the goods receipt, the supplier bill.
            // From any figure in a statement a human must be able to reach the
            // event that produced it, and that chain is the entire value of
            // building this rather than buying it.
            $table->nullableMorphs('source');

            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');

            // ⚠ A correction is a new, opposite journal - never an edit. Set on
            // the REVERSING journal and pointing at the one it undoes.
            $table->foreignId('reverses_journal_id')->nullable()->constrained('journals')->restrictOnDelete();

            // ⚠ created_at ONLY, no updated_at. A table with no update timestamp
            // tells the next person what kind of table it is before they read a
            // line of code. stock_movements does the same.
            $table->timestamp('created_at')->nullable();

            // ⚠ The real guarantee that two journals cannot share a number. The
            // sequence table hands them out; THIS is what makes it true even if
            // two requests race, which is the lesson from the supplier bill.
            $table->unique(['business_id', 'journal_number']);
            $table->index(['business_id', 'journal_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            // ⚠ RESTRICT, not cascade. A journal can never be deleted, so a
            // cascade here would only ever fire for something that is not
            // supposed to be possible.
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();

            // ⚠⚠ DENORMALISED FROM THE JOURNAL, on purpose. A trial balance
            // groups lines by account for one business and would otherwise join
            // every line back to its journal to find out whose it is.
            //
            // ⚠ It is genuinely a second copy, and the integrity command checks
            // the two agree - exactly as stock_movements carries its own
            // warehouse_id independent of its batch's, which is why level 2 of
            // inventory:verify-ledger exists and catches a transfer leg written
            // against the wrong warehouse.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('line_no');

            // ⚠ RESTRICT. An account that has ever been posted to can never be
            // deleted, only deactivated. Enforced in the model too, so the user
            // gets a sentence instead of a constraint violation.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();

            // ⚠⚠ WHOLE PAISA, SIGNED: positive is a debit, negative a credit.
            // See Support\Money. A journal balances when these sum to exactly
            // zero, and "exactly" is a word floating point cannot honour.
            $table->bigInteger('amount_minor');

            $table->string('memo')->nullable();

            // Who this line is ABOUT: the supplier owed, the employee paid, the
            // customer who owes. What makes Accounts Payable a subsidiary ledger
            // rather than one undifferentiated balance.
            $table->nullableMorphs('party');

            // Which bank account, cash box or wallet the money moved through.
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->unique(['journal_id', 'line_no']);
            // The trial balance's own query: by business, by account.
            $table->index(['business_id', 'account_id']);
        });

        Schema::create('journal_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The year the fiscal year STARTS in. Bor de Guna runs 1 July to
            // 30 June (C-GL-4), so 2026 means 2026-07-01 to 2027-06-30.
            $table->unsignedSmallInteger('fiscal_year');

            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['business_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_sequences');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
    }
};
