<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSLCommerz slice P4: money sent back to a customer through the gateway itself
 * (Joy, 13 September answer 4; 16 September: Owner only, partial allowed).
 *
 * ⭐⭐ THE ROW IS WRITTEN AND COMMITTED BEFORE SSLCOMMERZ IS ASKED. A request that
 * times out still exists, still reserves the money on its order, and the next
 * check asks SSLCommerz again under the same `refund_trans_id` instead of a
 * second click refunding twice.
 *
 * ⚠ `refund_trans_id` is UNIQUE: SSLCommerz made it mandatory on 24 February
 * 2025 and uses it to tell one refund of a payment from another. `refund_ref_id`
 * is SSLCommerz's own id for the refund, unique once it exists.
 *
 * ⚠ The books move only when SSLCommerz says the money is back with the
 * customer (`refunded`): the customer refund it becomes is linked here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();

            // {payment tran_id}-R{n}. SSLCommerz allows 30 characters.
            $table->string('refund_trans_id', 30)->unique();
            $table->decimal('amount', 15, 4);
            $table->string('remarks', 255);

            // requested | processing | confirmed | refunded | failed | cancelled
            $table->string('status', 20)->default('requested');

            // What SSLCommerz said, kept as it said it.
            $table->string('refund_ref_id', 50)->nullable()->unique();
            $table->string('gateway_status', 30)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            // Why SSLCommerz refused it, or why booking it failed, in words.
            $table->string('problem', 255)->nullable();

            // The refund that booked it.
            $table->foreignId('customer_refund_id')->nullable()->constrained()->restrictOnDelete();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['sales_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_refunds');
    }
};
