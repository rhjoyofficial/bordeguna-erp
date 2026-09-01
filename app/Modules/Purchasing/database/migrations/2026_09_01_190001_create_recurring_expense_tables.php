<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing costs: the subscriptions and monthly bills that arrive whether or not
 * anybody remembers them (Joy, 1 September 2026).
 *
 * ⛔⛔ NEITHER TABLE IS ACCOUNTING. An expense voucher in this ERP is PAID the
 * moment it is recorded, and a service owed but unpaid has no payable to sit in.
 * So a schedule may predict and remind, and it may fill a form in - but nothing
 * here ever writes a voucher or a journal by itself. The first month a card is
 * declined, anything else would show money leaving a bank it never left.
 *
 * Two tables because they answer two different questions. The schedule is the
 * COMMITMENT ("4,900 to Nikola Host on the last day of every month"); the
 * occurrence is one PERIOD of it ("September's 4,900, still due").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            // Where it lands. Required: an expense with no account is an amount.
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();

            // ⚠ The account it USUALLY leaves. A default offered to the form
            // later, never a fact - the fact is on the voucher.
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->nullOnDelete();

            $table->decimal('amount', 14, 2);

            // ⚠ True for anything billed in dollars. 20 USD is never the same
            // number of taka twice, and without this flag every month's variance
            // reads as a mistake.
            $table->boolean('amount_varies')->default(false);

            $table->string('frequency');

            /**
             * ⚠⚠ 31 IS A LEGAL VALUE and means "the last day". Nikola Host is
             * due on the last day of the month, which is the 28th, 29th, 30th or
             * 31st depending on the month - storing 29 would have been wrong in
             * eleven months out of twelve. The generator clamps.
             */
            $table->unsignedTinyInteger('day_of_month');

            $table->date('start_date');
            $table->date('end_date')->nullable();

            $table->string('status')->default('active');

            // `NIKOLA` produces `NIKOLA-2026-09`. ⛔ Our convention, never
            // something shaped like the vendor's own invoice number.
            $table->string('reference_prefix')->nullable();

            $table->unsignedSmallInteger('lead_days')->default(3);
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('recurring_expense_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_expense_id')->constrained()->cascadeOnDelete();

            $table->date('due_on');
            $table->string('period_label');

            /**
             * ⚠⚠ COPIED at generation, never joined back to the schedule. A price
             * rise next year must not restate what last March expected - the same
             * discipline the payroll register follows in reading finished runs
             * rather than recomputing them.
             */
            $table->decimal('expected_amount', 14, 2);

            $table->string('status')->default('due');

            // What satisfied it. The link back to real money.
            $table->foreignId('expense_voucher_id')->nullable()->constrained('expense_vouchers')->nullOnDelete();

            $table->text('skipped_reason')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();

            /**
             * ⚠⚠ `recurring:{id}:{YYYY-MM}`, unique per business. The nightly roll
             * is idempotent BY CONSTRUCTION rather than by remembering to check:
             * run it twice, restore a backup and run it again, and no duplicate
             * can exist. Same guarantee `LeaveLedgerEntry::once` and
             * `PayrollAdjustment::once` rely on.
             */
            $table->string('source_key');

            $table->timestamps();

            $table->unique(['business_id', 'source_key']);
            $table->index(['business_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expense_occurrences');
        Schema::dropIfExists('recurring_expenses');
    }
};
