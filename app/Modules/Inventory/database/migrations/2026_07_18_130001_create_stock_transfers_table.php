<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Links to the out/in stock_movements legs carrying the same value
            // (see StockMovement::transfer_group_id) — set at dispatch time.
            $table->uuid('transfer_group_id')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('target_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('reason_id')->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            // in_transit | received
            $table->string('status')->default('in_transit');
            $table->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
