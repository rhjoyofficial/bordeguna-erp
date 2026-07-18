<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered', 15, 4);
            // Running total across every GoodsReceiptLine against this line —
            // incremented by RecordGoodsReceipt, never edited directly.
            $table->decimal('quantity_received', 15, 4)->default(0);
            // The negotiated purchase price — becomes the StockBatch's unit_cost
            // once received (this is how acquisition cost enters FIFO costing).
            $table->decimal('unit_cost', 15, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
