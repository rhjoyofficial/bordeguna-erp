<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // adjustment | transfer
            $table->string('type');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // required when type = transfer
            $table->foreignId('target_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            // increase | decrease — only meaningful when type = adjustment
            $table->string('direction')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->text('reason_note');
            // pending | approved | rejected
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Points at the primary movement created on approval (the out-leg for
            // transfers). transfer_group_id on stock_movements links the full pair.
            $table->foreignId('resulting_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};
