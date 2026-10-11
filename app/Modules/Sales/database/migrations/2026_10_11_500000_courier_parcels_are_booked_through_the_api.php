<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier hub slice 4 (11 Oct 2026): a parcel booked through the courier's API
 * from the order screen, and followed by webhook and by a 15-minute pull.
 *
 * ⚠⚠ NOTHING HERE IS MONEY. `consignments` stays the money-side record and its
 * `status` still moves only through RecordConsignmentOutcome, by a person
 * (ruling 1). `courier_status` is what the COURIER says, kept beside it.
 *
 * courier_booking_requests (ruling 13): the intent, written BEFORE the HTTP
 * call, so a timeout leaves a row that says "we may have booked this" instead
 * of nothing, and nobody books it twice by pressing the button again.
 *
 * courier_shipment_events: the courier's timeline, from the webhook, the pull
 * and our own actions. Unique (consignment, event, occurred_at), so the same
 * webhook delivered twice is one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table) {
            // ⚠⚠ Pathao's account-level value we must echo on every webhook
            // answer. Encrypted by the cast, never sent to a browser.
            $table->text('integration_secret')->nullable();
            // Ruling 1: stored and shown, OFF, until the evidence period ends.
            $table->boolean('auto_post_outcomes')->default(false);
            $table->timestamp('auto_post_switched_off_at')->nullable();
        });

        Schema::table('consignments', function (Blueprint $table) {
            $table->foreignId('courier_account_id')->nullable()->constrained('courier_accounts')->restrictOnDelete();
            $table->string('provider_consignment_id', 100)->nullable();
            // What we sent as merchant_order_id: the challan number.
            $table->string('merchant_order_ref', 60)->nullable();
            $table->string('booked_via', 10)->default('manual');
            $table->decimal('provider_fee_quoted', 15, 4)->nullable();
            $table->decimal('provider_cod_fee_quoted', 15, 4)->nullable();
            $table->string('courier_status', 30)->nullable();
            $table->string('courier_status_raw', 80)->nullable();
            $table->timestamp('courier_status_at')->nullable();
            $table->timestamp('status_synced_at')->nullable();
            $table->string('courier_city_id', 30)->nullable();
            $table->string('courier_zone_id', 30)->nullable();
            $table->string('courier_area_id', 30)->nullable();
            $table->unsignedInteger('hub_weight_grams')->nullable();
            // What the courier SAYS it collected. A hint for the person who
            // confirms the outcome; never posted from here.
            $table->decimal('courier_collected_amount', 15, 4)->nullable();
            $table->string('courier_invoice_ref', 60)->nullable();

            $table->unique(['courier_account_id', 'consignment_number'], 'consignments_courier_account_number_unique');
            $table->index(['courier_account_id', 'courier_status']);
            $table->index(['courier_account_id', 'merchant_order_ref']);
        });

        Schema::create('courier_booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('courier_account_id')->constrained('courier_accounts')->restrictOnDelete();
            $table->string('merchant_order_id', 60);
            $table->string('request_hash', 64);
            $table->string('status', 12);
            // ⚠ No name, phone or address: ids, amounts, the courier's message.
            $table->json('response_summary')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('provider_consignment_id', 100)->nullable();
            $table->foreignId('consignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->timestamps();

            $table->index(['delivery_id', 'status']);
            $table->index(['courier_account_id', 'status']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('courier_shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consignment_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10);
            $table->string('event', 80);
            $table->string('raw_status', 80)->nullable();
            $table->string('canonical_status', 30)->nullable();
            $table->timestamp('occurred_at');
            $table->string('payload_hash', 64)->nullable();
            // Whether this event moved the parcel's courier status.
            $table->boolean('applied')->default(false);
            // ⚠ A whitelist of non-PII fields: amounts, attempt, reason, invoice.
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['consignment_id', 'event', 'occurred_at'], 'courier_events_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_shipment_events');
        Schema::dropIfExists('courier_booking_requests');

        // ⚠ The foreign key first: MySQL refuses to drop the last index a
        // foreign key leans on.
        Schema::table('consignments', function (Blueprint $table) {
            $table->dropForeign(['courier_account_id']);
        });

        Schema::table('consignments', function (Blueprint $table) {
            $table->dropUnique('consignments_courier_account_number_unique');
            $table->dropIndex(['courier_account_id', 'courier_status']);
            $table->dropIndex(['courier_account_id', 'merchant_order_ref']);
            $table->dropColumn([
                'courier_account_id', 'provider_consignment_id', 'merchant_order_ref', 'booked_via', 'provider_fee_quoted',
                'provider_cod_fee_quoted', 'courier_status', 'courier_status_raw', 'courier_status_at', 'status_synced_at',
                'courier_city_id', 'courier_zone_id', 'courier_area_id', 'hub_weight_grams',
                'courier_collected_amount', 'courier_invoice_ref',
            ]);
        });

        Schema::table('courier_accounts', function (Blueprint $table) {
            $table->dropColumn(['integration_secret', 'auto_post_outcomes', 'auto_post_switched_off_at']);
        });
    }
};
