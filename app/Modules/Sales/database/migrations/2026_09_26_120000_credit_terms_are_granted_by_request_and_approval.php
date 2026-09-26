<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ CREDIT TERMS ARE GRANTED, NOT TYPED (Joy, 26 Sep 2026, Q16): the limit and
 * the days for every dealer, distributor, corporate buyer and shop come from
 * whoever brought that customer in, as a bulk file, and "approval from the
 * authority must needs". Until now anyone with `sales.customers.manage` could
 * type a credit limit on the customer form or import one, and it took effect
 * at once.
 *
 * A credit terms request is that file as a document: one row per customer with
 * the figures as they are and as proposed, an approval request through the
 * ordinary engine (workflow key `credit_terms_request`, permission
 * `sales.credit.approve`), and nothing on the customer changes until the
 * approval completes. The lines keep the CURRENT figures too, so the approver
 * sees the change, not only the destination, and the record still reads
 * right after the customer has moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_terms_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('request_number', 40);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->string('source_file', 255)->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'request_number']);
        });

        Schema::create('credit_terms_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_terms_request_id')->constrained('credit_terms_requests')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('current_credit_limit', 15, 4)->nullable();
            $table->unsignedSmallInteger('current_credit_days')->nullable();
            $table->decimal('proposed_credit_limit', 15, 4)->nullable();
            $table->unsignedSmallInteger('proposed_credit_days')->nullable();
            // Set at approval: what the customer really had the moment the line
            // was applied, which may differ from `current_*` if somebody edited
            // the customer between submission and approval.
            $table->decimal('applied_over_credit_limit', 15, 4)->nullable();
            $table->unsignedSmallInteger('applied_over_credit_days')->nullable();
            $table->timestamps();

            $table->unique(['credit_terms_request_id', 'customer_id'], 'credit_terms_lines_one_per_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_terms_request_lines');
        Schema::dropIfExists('credit_terms_requests');
    }
};
