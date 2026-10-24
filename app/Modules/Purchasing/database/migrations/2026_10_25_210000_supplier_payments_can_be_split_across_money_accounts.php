<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B2 (R57, 25 Oct 2026): one supplier payment paid from several
 * money accounts ("some from bank, some from bKash, the rest stays due").
 *
 * One row per account, the cash that left THAT account. Written only when a
 * payment is split: a payment from one account keeps reading its single
 * `money_account_id` exactly as before, so nothing is backfilled and every
 * existing payment, report and test stays as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_money_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('money_account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            // ⚠ The same account twice in one payment is one account: refused by
            // the action with a sentence, and by this index if anything slips.
            $table->unique(['supplier_payment_id', 'money_account_id'], 'sp_money_lines_payment_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_money_lines');
    }
};
