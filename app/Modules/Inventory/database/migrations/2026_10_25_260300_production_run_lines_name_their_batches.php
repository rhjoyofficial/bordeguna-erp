<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Initial plan B8 (Joy, 24 Oct 2026; Q8 = B, R31): "Instead of
 * auto-consuming, manually select the specific batch" for every input of a
 * production run. No proposal: the person picks every batch, a line may be
 * split across several, and confirm takes exactly what was picked, so the
 * finished batch costs exactly what went into it.
 *
 * One row per (line, batch): how much of the line, in the product's own stock
 * unit, comes out of that batch. The quantities of a line add up to its
 * quantity_consumed (RecordProductionRun::prepare checks it). Structure only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('production_run_line_batches')) {
            return;
        }

        Schema::create('production_run_line_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // A draft's lines are rewritten on Edit: their picks go with them.
            $table->foreignId('production_run_line_id')->constrained('production_run_lines')->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->timestamps();

            $table->unique(['production_run_line_id', 'stock_batch_id'], 'prl_batches_line_batch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_run_line_batches');
    }
};
