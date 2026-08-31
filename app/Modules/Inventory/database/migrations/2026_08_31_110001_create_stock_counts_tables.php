<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock counts / cycle counting (C-CNT). The count is a questionnaire, never a
 * stock document: its variances become ordinary InventoryAdjustments through
 * CreateInventoryAdjustment, whose `source` morph points back at the count
 * line. Nothing in these tables moves stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // RESTRICT: a warehouse with count history stays; the warehouse
            // controller turns the refusal into a sentence.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('count_number', 30);
            $table->string('status', 20)->default('draft');

            // C-CNT-8: the scope the counter walked, informational. Nulled if
            // the category or brand itself is later removed.
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            // C-CNT-5: one reason for the whole count, chosen at completion.
            $table->foreignId('reason_id')->nullable()->constrained()->restrictOnDelete();

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'count_number']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // The book at the moment the count STARTED - for the paper and the
            // history. The variance is sized against live stock at completion
            // (C-CNT-2), stored in `live_quantity` so the arithmetic stays
            // auditable after the fact.
            $table->decimal('expected_quantity', 16, 4);
            $table->decimal('counted_quantity', 16, 4)->nullable();
            $table->decimal('live_quantity', 16, 4)->nullable();

            // C-CNT-4: the human-decided cost for a found-stock increase.
            $table->decimal('unit_cost', 16, 4)->nullable();

            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
