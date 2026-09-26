<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an HQ draft became an order, and how HQ cancelled one (HQ docs/88 A point 3, docs/79 F).
     *
     * ⭐ TWO RECORDS BESIDE THE ORDER, NEVER A CHANGE TO IT OR TO THE DRAFT. The draft stays exactly as it
     * was written (it is immutable, and HQ reconciles against it); the order is a normal sales order made
     * by `CreateSalesOrder`, pending in the ERP's approval inbox. What links the two, with the key HQ
     * sent and what exactly it asked, is `sales_order_hq_confirmations`: one draft makes at most one
     * order (unique), one order comes from at most one draft (unique). A cancellation HQ asked for is
     * `sales_order_hq_cancellations`: one per order, one per key.
     *
     * ⚠ Additive only: two new tables. `sales_orders` and `sales_order_drafts` are not touched. Every
     * index named explicitly (the 64-character MySQL ceiling).
     */
    public function up(): void
    {
        Schema::create('sales_order_hq_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_draft_id')->constrained('sales_order_drafts')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();

            $table->string('idempotency_key', 128);
            $table->char('payload_digest', 64);
            $table->json('hq_reference')->nullable();

            $table->string('correlation_id', 128)->nullable();
            $table->unsignedBigInteger('hq_token_id')->nullable();
            // Who asked (the HQ integration user). ⚠ Not who created the order: that is the system
            // actor, which holds no approval, so the order always waits for a person in the ERP.
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key'], 'so_hq_conf_business_key_unique');
            $table->unique(['sales_order_draft_id'], 'so_hq_conf_draft_unique');
            $table->unique(['sales_order_id'], 'so_hq_conf_order_unique');
        });

        Schema::create('sales_order_hq_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();

            $table->string('idempotency_key', 128);
            $table->char('payload_digest', 64);
            $table->string('reason', 255);
            $table->json('hq_reference')->nullable();

            $table->string('correlation_id', 128)->nullable();
            $table->unsignedBigInteger('hq_token_id')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key'], 'so_hq_cancel_business_key_unique');
            $table->unique(['sales_order_id'], 'so_hq_cancel_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_hq_cancellations');
        Schema::dropIfExists('sales_order_hq_confirmations');
    }
};
