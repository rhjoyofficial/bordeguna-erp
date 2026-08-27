<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money moving between the business's own accounts.
 *
 * ⚠⚠ THE BUSINESS HAD FIVE MONEY ACCOUNTS AND NO WAY TO MOVE BETWEEN THEM.
 * Withdrawing cash from the bank to the till, or cashing out bKash into the
 * bank, could only be recorded as a hand-written journal - which in practice
 * meant it was not recorded at all, and the cash accounts drifted from reality.
 *
 * ⚠ THE FEE IS A FIRST-CLASS COLUMN, not an afterthought. A bKash cash-out
 * costs about 1.85%, so the amount that leaves is NOT the amount that arrives.
 * A transfer document that cannot express that is wrong in Bangladesh, and the
 * person using it would have to remember to raise a separate expense voucher
 * that nobody would connect to the transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('transfer_number', 32);
            $table->date('transferred_on');

            $table->foreignId('from_money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('to_money_account_id')->constrained('money_accounts')->restrictOnDelete();

            // What ARRIVES at the destination.
            $table->decimal('amount', 15, 2);
            // What the move COST, on top of the amount. Debited to an expense
            // account, so the source is reduced by amount + fee.
            $table->decimal('fee', 15, 2)->default(0);
            // ⚠ Nullable because a fee-free transfer names no expense account.
            // Required whenever fee > 0, enforced in the action rather than here.
            $table->foreignId('fee_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // ⚠⚠ The real guarantee against two transfers sharing a number. The
            // action computes max-plus-one inside its transaction, which is
            // honest for one person at a keyboard; a genuine race surfaces here
            // as a rejected save rather than as duplicate documents.
            $table->unique(['business_id', 'transfer_number']);
            $table->index(['business_id', 'transferred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_transfers');
    }
};
