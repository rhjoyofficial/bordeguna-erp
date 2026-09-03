<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Staff Product Benefit (office order BDG/HR/09/01/2026). The request is
// the accountability wrapper; the handover becomes a REAL counter sale.
// Snapshots of staff price and MRP are taken at apply time, because the
// cap that was checked is the cap that must still be readable later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('number')->unique();
            $table->unsignedBigInteger('employee_id')->index();
            $table->string('payment_mode');
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('fulfilled_by')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedBigInteger('sales_invoice_id')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('staff_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->foreignId('request_id')->constrained('staff_purchase_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('staff_price', 12, 2);
            $table->decimal('mrp', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_purchase_lines');
        Schema::dropIfExists('staff_purchase_requests');
    }
};
