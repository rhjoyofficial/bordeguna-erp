<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money an employee spent out of their own pocket on the company's behalf.
 *
 * ⚠⚠ THE GAP THIS FILLS. An employee who buys packing tape or pays a courier
 * had no way to ask for it back. Somebody with finance access had to hear about
 * it, be handed the receipt, and type a payment voucher on their behalf - so the
 * claim lived on a scrap of paper until then, and the employee had no record
 * that they had asked.
 *
 * ⚠ A claim is NOT the money leaving. It is a request, and it stays a request
 * until somebody settles it. That is the difference between this and an expense
 * voucher, which IS the payment.
 *
 * ⚠⚠ TWO WAYS TO SETTLE, BOTH REAL (Joy, 27 Aug 2026): pay it now from cash,
 * bank or an MFS wallet, or add it to the next salary. They end in different
 * documents - a payment voucher or a payroll adjustment - and the claim records
 * which one, so "how was I paid back" always has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('claim_number', 30);
            $table->date('spent_on');
            $table->decimal('amount', 15, 4);

            // What it was for, in the employee's own words. Required: a claim
            // with no explanation cannot be approved by anybody honestly.
            $table->text('purpose');

            // ⚠ Set by finance at settlement, not by the employee. Somebody
            // buying tape should not be choosing a ledger head, and letting
            // them would put the classification in the hands of the person
            // least able to get it right.
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            // 'payment' or 'payroll'. Null until settled.
            $table->string('settlement_method', 20)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();

            // Whichever document actually paid it. Exactly one is ever set.
            $table->foreignId('expense_voucher_id')->nullable()->constrained('expense_vouchers')->nullOnDelete();
            $table->foreignId('payroll_adjustment_id')->nullable()->constrained('payroll_adjustments')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'claim_number']);
            $table->index(['business_id', 'employee_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};
