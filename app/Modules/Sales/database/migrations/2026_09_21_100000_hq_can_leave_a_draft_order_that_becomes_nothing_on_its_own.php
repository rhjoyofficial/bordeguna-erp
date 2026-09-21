<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A draft sales order from HQ (HQ docs/83 C.3, docs/79): what HQ proposed and a person approved,
     * priced and checked by the ERP, and NOTHING ELSE.
     *
     * ⚠⚠ NOT A SALES ORDER. `sales_orders` has no draft status, and the one path that writes it
     * (`CreateSalesOrder`) takes the next order number, opens an approval, notifies the approvers and,
     * through the website, may create a customer. A draft must do none of that, so it is its own
     * record: no number, no approval, no customer, no stock, no journal. Turning a draft into an order
     * is a later capability with its own gate; nothing in this schema moves a draft anywhere.
     *
     * ⚠ Additive only: two new tables, nothing else touched. Every index named explicitly (the
     * 64-character MySQL ceiling).
     */
    public function up(): void
    {
        Schema::create('sales_order_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();

            // ⚠ Always `draft`. There is no other value and no code that writes one.
            $table->string('status', 16)->default('draft');

            // The caller's key, and what exactly was asked under it: the same key with the same
            // payload replays this row, the same key with another payload is refused.
            $table->string('idempotency_key', 128);
            $table->char('payload_digest', 64);

            // ⭐ The immutable link back to HQ: the fingerprint of the proposal a person approved,
            // and HQ's own references, so HQ can reconcile proposal, approval and this row.
            $table->char('hq_proposal_fingerprint', 64);
            $table->json('hq_reference')->nullable();

            // The customer `identify` would pick for the phone, or none. Nobody is ever created here.
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('customer_resolution', 16);

            // ⚠ ONE PERSON, as on the website: the buyer is the recipient (`PlaceWebsiteOrder`
            // identifies the customer by the recipient's phone). Whether an HQ order may name a
            // recipient other than the buyer is an open business question (HQ docs/79 H.7).
            $table->string('recipient_name', 120);
            $table->string('recipient_phone', 32);
            $table->string('recipient_phone_normalized', 16);
            $table->string('district', 64);
            $table->string('thana', 64);
            $table->string('area', 120)->nullable();
            $table->string('postcode', 16)->nullable();
            $table->string('address_line', 255);
            $table->string('landmark', 255)->nullable();

            $table->string('payment_method', 16);
            $table->decimal('goods_total', 15, 2);
            $table->decimal('delivery_charge', 15, 2);
            $table->decimal('order_discount', 15, 2)->default(0);
            $table->decimal('agreed_total', 15, 2);
            $table->timestamp('priced_at');

            $table->string('correlation_id', 128)->nullable();
            $table->unsignedBigInteger('hq_token_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key'], 'so_drafts_business_key_unique');
            $table->index(['business_id', 'hq_proposal_fingerprint'], 'so_drafts_business_fingerprint_index');
        });

        Schema::create('sales_order_draft_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_draft_id')->constrained('sales_order_drafts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_price', 15, 4);
            $table->decimal('line_total', 15, 2);
            $table->string('price_note', 255)->nullable();
            // What the shop said about this product when the draft was made; a fact of that moment.
            $table->boolean('made_to_order')->default(false);
            $table->timestamps();

            $table->index(['sales_order_draft_id'], 'so_draft_lines_draft_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_draft_lines');
        Schema::dropIfExists('sales_order_drafts');
    }
};
