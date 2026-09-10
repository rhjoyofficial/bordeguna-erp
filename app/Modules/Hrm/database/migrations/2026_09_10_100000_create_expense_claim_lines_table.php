<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One claim, several spends (Joy, 9 September 2026).
 *
 * ⚠⚠ THE COMPLAINT THIS ANSWERS, IN HIS WORDS. A market trip is goods, a
 * rickshaw, a courier and tea for the supplier. Under one-claim-one-amount that
 * is four claims, four approvals, four payments and four vouchers for a single
 * afternoon. The claim becomes a HEADER with LINES: the employee builds it over
 * the day, submits once, it is approved once and paid once, and one voucher
 * lists every line.
 *
 * ⚠⚠ THE CATEGORY IS NULLABLE, AND THAT IS NOT SLOPPINESS. Two claims raised on
 * 2 September predate categories entirely, and their backfilled line has to be
 * able to say so. Everything that posts asks whether a line HAS a category
 * rather than assuming one, exactly as the header used to.
 *
 * ⚠ `declined_at` is the approver's per-line no. Only lines with no decline
 * post to the ledger and only they are paid, so a whole claim no longer waits
 * on one bad taxi receipt - the good lines go through and the employee is told
 * which one was refused and why.
 *
 * ⚠ Receipts hang off the LINE, not the claim: a trip's four spends have four
 * separate pieces of paper, and one collection on the header could not say
 * which proof belonged to which spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();

            $table->date('spent_on');

            // ⚠ Nullable for the two legacy claims only. A line raised on the
            // new screen always carries one; the form refuses without it.
            $table->unsignedBigInteger('expense_category_id')->nullable();

            // Stamped from the category at approval, so a later remapping of
            // the category cannot silently rewrite where a posted line went.
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->decimal('amount', 15, 4);
            $table->text('description')->nullable();

            // The approver's per-line refusal. A reason is required with it -
            // "declined" with no words is a decision the employee cannot act on.
            $table->timestamp('declined_at')->nullable();
            $table->text('declined_reason')->nullable();

            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['expense_claim_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_lines');
    }
};
