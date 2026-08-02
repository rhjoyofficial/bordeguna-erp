<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1.1 of Accounting: where money actually is.
     *
     * ⚠⚠ There was no concept of a bank or cash account anywhere in this ERP.
     * Every payment event in the business walk - paying a supplier, paying
     * salaries, taking a customer receipt, petty cash - ended in a credit to an
     * account that did not exist, which blocked Accounts Payable, Accounts
     * Receivable, the cash book and bank reconciliation simultaneously.
     *
     * ⚠ NO BALANCE COLUMN, deliberately. A money account is an IDENTITY, not a
     * running total: its balance is a ledger balance and arrives with the
     * General Ledger, derived exactly as stock quantities are derived from
     * movements. Storing one here would create the second source of truth that
     * four Inventory slices were spent removing.
     *
     * ℹ Each account maps to a Chart of Accounts entry in Phase 2. That is one
     * nullable column added then, not now.
     */
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // bank | cash | mfs - see MoneyAccountType. Each reconciles against
            // something different: a statement, a physical count, a provider.
            $table->string('type');

            // Everything below is nullable because CASH needs none of it, which
            // is precisely why cash is the only type that can never be wrong
            // about how somebody was paid.
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('branch')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('provider')->nullable();

            $table->string('currency', 3)->default('BDT');
            $table->text('note')->nullable();

            // ⚠ Deactivated, never deleted: journals and disbursements refer to
            // it forever, and an account that paid somebody in August must still
            // be nameable in December.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['business_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_accounts');
    }
};
