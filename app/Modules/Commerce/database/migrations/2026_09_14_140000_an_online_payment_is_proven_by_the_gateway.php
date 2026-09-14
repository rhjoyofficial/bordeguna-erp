<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSLCommerz slice P2b: one row per attempt to pay an order online.
 *
 * ⭐⭐ MONEY THE GATEWAY TOOK IS NEVER LOST. Proving a payment and booking it are
 * two steps, committed separately: once the gateway's signature and Validation
 * API agree, the proof is saved (`proven`) on its own; booking it - the receipt on
 * the order and the fee - follows and, if it fails (a mapping removed, a period
 * closed), the row stays `proven`, visible and retryable, instead of the proof
 * being rolled back with the accounting.
 *
 * ⚠ `val_id` IS UNIQUE: the gateway's id for a validated transaction can prove
 * exactly one attempt. Idempotency in the database, not only a status check
 * under a lock.
 *
 * ⚠ `tran_id` is unique across every business, for the reason the order prefix
 * is: a shared merchant's transaction must identify one attempt in the group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);

            // {shop reference}-{attempt}. SSLCommerz allows 30 characters.
            $table->string('tran_id', 30)->unique();
            $table->unsignedInteger('attempt');

            // What this attempt asked the customer to pay.
            $table->decimal('amount', 15, 4);

            // initiated | proven | paid | failed | cancelled | expired
            $table->string('status', 20)->default('initiated');

            // Sandbox or live when the attempt was opened, kept with the attempt.
            $table->boolean('is_live')->default(false);

            // What the gateway proved. Null until then.
            $table->string('val_id', 80)->nullable()->unique();
            $table->string('bank_tran_id', 80)->nullable();
            $table->decimal('amount_paid', 15, 4)->nullable();
            $table->decimal('store_amount', 15, 4)->nullable();
            $table->string('card_type', 60)->nullable();
            $table->unsignedTinyInteger('risk_level')->nullable();
            $table->string('risk_title', 120)->nullable();
            $table->timestamp('proven_at')->nullable();

            // The receipt that booked it.
            $table->foreignId('customer_receipt_id')->nullable()->constrained()->restrictOnDelete();

            // Why a callback was refused or booking failed, in words.
            $table->string('problem', 255)->nullable();

            $table->timestamps();

            $table->unique(['sales_order_id', 'attempt']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payments');
    }
};
