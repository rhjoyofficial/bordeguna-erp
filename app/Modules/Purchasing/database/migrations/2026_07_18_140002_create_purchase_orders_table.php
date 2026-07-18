<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('po_number');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // Single destination warehouse per PO — every line receives here.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // open | partially_received | received | cancelled
            $table->string('status')->default('open');
            // unpaid | partially_paid | paid — informational only, no ledger.
            // Real AP tracking is a Phase 4 (Accounting) concern.
            $table->string('payment_status')->default('unpaid');
            $table->date('expected_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'po_number']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
