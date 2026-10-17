<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock reserved for a document that has been approved but not yet handed
 * over (Phase 2, 17 Oct 2026: staff purchase at cost, owner withdrawal).
 *
 * ⭐ ChatGPT ruling Q1: the staff price is the cost of the SPECIFIC FIFO batch
 * reserved at approval, frozen on the request. A reservation is what makes
 * that price stable between approval and handover: FIFO consumption and the
 * stock a counter or an order may offer both subtract what is reserved for
 * somebody else (`ConsumesFifoStock`, `Product::sellableStockQuantity`).
 *
 * ⚠ COORDINATE (plan F4): builders E and F create this table with exactly
 * these columns, so whichever branch merges second drops its copy. One row
 * per batch slice; `released_at` set when the goods leave or the request is
 * cancelled, never deleted, so "what was held on the 20th" stays answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_reservations')) {
            return;
        }

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('stock_batch_id');
            $table->decimal('quantity', 15, 4);
            $table->string('reason');
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['stock_batch_id', 'released_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
