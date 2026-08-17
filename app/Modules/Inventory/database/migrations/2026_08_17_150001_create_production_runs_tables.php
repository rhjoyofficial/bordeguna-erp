<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing phase 1: the production run, the sixth sanctioned stock
 * document. One run = consumed THESE input lines (FIFO), produced THIS output
 * batch, and here is the waste we measured.
 *
 * Design note and the approved Corrections C-MFG-1..14:
 * MANUFACTURING-PHASE1-PACKING-ARCHITECTURE.md.
 *
 * ⚠⚠ C-MFG-2: NO CONVERSION COLUMNS ANYWHERE HERE. The run records declared
 * reality - quantities the operator measured, each in its product's own stock
 * unit. A density factor is never applied as arithmetic; where the output
 * product declares its net content (below), the factor-free comparison serves
 * as a flag-only reasonableness check stored in `yield_check`.
 *
 * ⚠ C-MFG-1: one run, one output. A session filling two pack sizes from one
 * drum is two runs - that is what makes cost allocation a division instead of
 * a policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('run_number');
            // draft | confirmed | reversed
            $table->string('status')->default('draft');

            $table->foreignId('output_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('output_quantity', 15, 4);
            // Null until confirm creates the batch (C-MFG-8).
            $table->foreignId('output_batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->date('output_expiry_date')->nullable();
            // ⚠ Informational label data ONLY (C-MFG-13 / D-MFG-6). The selling
            // price resolver reads product_prices and never this column.
            $table->decimal('output_mrp', 15, 4)->nullable();

            // ⚠ C-MFG-4: waste is absorbed into the output cost by default. This
            // per-run override routes the waste share to a loss account instead,
            // for abnormal events (a dropped carton), through the mapping key
            // `inventory.production_wastage` - never a hard-coded account.
            $table->boolean('expense_waste')->default(false);

            // ⚠ STORED at confirm, not recomputed - the same lesson as
            // `value_delta`: deriving money later gives a different number for
            // the same event. Taka at four places, like every stock value.
            $table->decimal('consumed_value', 15, 4)->nullable();
            $table->decimal('waste_value', 15, 4)->nullable();

            // C-MFG-2's provenance: expected content, net consumption, deviation,
            // flag - written at confirm, same pattern as attendance `calculation`.
            $table->json('yield_check')->nullable();

            $table->dateTime('occurred_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'run_number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('production_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // What was consumed, in the product's own stock unit, INCLUDING the
            // measured waste below - 100 kg consumed of which 2 kg spilled.
            $table->decimal('quantity_consumed', 15, 4);
            $table->decimal('waste_quantity', 15, 4)->default(0);
            // ⚠ STORED at confirm (C-MFG-11): the line's FIFO-consumed value and
            // the waste share at that line's average consumed cost.
            $table->decimal('consumed_value', 15, 4)->nullable();
            $table->decimal('waste_value', 15, 4)->nullable();
            $table->timestamps();

            // The consumed batch portions live on stock_movements, as everywhere.
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Which run a movement belongs to - the same seam as adjustment_id,
            // and what makes input-batch -> output-batch traceability a join.
            $table->foreignId('production_run_id')->nullable()->after('adjustment_id')
                ->constrained('production_runs')->nullOnDelete();
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            // C-MFG-13: batch identity for finished goods. Purchase-created
            // batches leave both null; the production run sets them.
            $table->date('manufactured_date')->nullable()->after('expiry_date');
            // ⚠ Informational label data ONLY. Nothing prices off a batch.
            $table->decimal('mrp', 15, 4)->nullable()->after('manufactured_date');
        });

        Schema::table('products', function (Blueprint $table) {
            // C-MFG-14: the net content of ONE stock unit of this product, in a
            // declared unit - a 5 L pack contains 5 of "Litre". Master data,
            // nullable, and PURELY a yield-check input: no arithmetic anywhere
            // converts with it (C-MFG-2). Null means "no declared content", and
            // the yield check is then silently off for this product.
            $table->decimal('content_quantity', 15, 4)->nullable()->after('low_stock_threshold');
            $table->foreignId('content_unit_id')->nullable()->after('content_quantity')
                ->constrained('units_of_measure')->restrictOnDelete();
        });

        Schema::table('inventory_settings', function (Blueprint $table) {
            // D-MFG-5: how far the implied yield may deviate before the run is
            // flagged. Null means the approved default of 5. Flag-only either
            // way - a run is NEVER blocked by this (Joy, 2026-08-17).
            $table->decimal('production_yield_tolerance_percent', 8, 4)->nullable()
                ->after('adjustment_approval_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_settings', function (Blueprint $table) {
            $table->dropColumn('production_yield_tolerance_percent');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['content_unit_id']);
            $table->dropColumn(['content_quantity', 'content_unit_id']);
        });

        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropColumn(['manufactured_date', 'mrp']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['production_run_id']);
            $table->dropColumn('production_run_id');
        });

        Schema::dropIfExists('production_run_lines');
        Schema::dropIfExists('production_runs');
    }
};
