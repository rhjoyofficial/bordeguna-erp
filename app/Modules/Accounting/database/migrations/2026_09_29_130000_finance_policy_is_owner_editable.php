<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The finance policy: every limit, cap and threshold the books obey, as one
 * owner-editable row per business (29 Sep 2026).
 *
 * The numbers are ChatGPT's recommended defaults from the finance go-live
 * review (FINANCE-POLICY-DECISIONS-2026-09-29.md), each one Joy's to change:
 * a second approval above Tk 20,000, the Income Tax Act 2023 s.55 bank-only
 * limits (Tk 50,000; Tk 5 lakh for raw material), a Tk 10,000 petty-cash
 * float and capitalisation threshold, a 10% staff-purchase recovery cap, bad
 * debt reviewed at 180 days (50%) and 365 days (100%), and a courier payout
 * expected within 7 days.
 *
 * ⚠ Money is whole paisa, like every other amount in the ledger.
 *
 * `money_accounts.overdraft_limit_minor` is the only place a balance may go
 * below zero: a bank with a sanctioned overdraft. A cash box or a wallet cannot
 * pay money it does not hold, so for them it stays 0 whatever is typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('refuse_negative_money')->default(true);
            $table->unsignedBigInteger('payment_second_approval_above_minor')->default(2_000_000);
            $table->unsignedBigInteger('advance_refund_owner_above_minor')->default(2_000_000);
            $table->unsignedBigInteger('cash_payment_limit_minor')->default(5_000_000);
            $table->unsignedBigInteger('raw_material_cash_limit_minor')->default(50_000_000);
            $table->unsignedBigInteger('petty_cash_float_minor')->default(1_000_000);
            $table->unsignedBigInteger('capitalisation_threshold_minor')->default(1_000_000);
            $table->unsignedTinyInteger('staff_purchase_cap_percent')->default(10);
            $table->unsignedSmallInteger('bad_debt_half_after_days')->default(180);
            $table->unsignedSmallInteger('bad_debt_full_after_days')->default(365);
            $table->unsignedSmallInteger('courier_settlement_alert_days')->default(7);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('money_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('overdraft_limit_minor')->default(0)->after('routing_number');
        });
    }

    public function down(): void
    {
        Schema::table('money_accounts', function (Blueprint $table) {
            $table->dropColumn('overdraft_limit_minor');
        });

        Schema::dropIfExists('finance_policies');
    }
};
