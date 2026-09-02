<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quotations (CRM spec section 13, phase 1). A quotation is a promise of a
// price with an expiry: it reserves no stock and posts nothing. Accepting
// it creates the sales order, carrying the quoted prices verbatim.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('quotation_number')->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->date('valid_until');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->text('terms')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 12, 2);
            $table->string('price_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
