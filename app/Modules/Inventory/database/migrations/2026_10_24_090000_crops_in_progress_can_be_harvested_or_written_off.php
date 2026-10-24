<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): the season's cost, collected on a "Crops
 * in Progress" asset head by payment vouchers and journals, becomes paddy
 * stock at harvest, or a loss when the crop fails.
 *
 *   crop_harvests        one harvest: the head it empties, the warehouse,
 *                        the amount moved (paisa), its one journal
 *                        (Dr Stock on Hand / Cr the crops head)
 *   crop_harvest_lines   the main product and any by-products (straw), each
 *                        with its share of the amount and the batch it made
 *   crop_write_offs      a crop failure: Dr a loss head / Cr the crops head,
 *                        with a reason, posted only once the Owner approves
 *
 * Both carry the Reversible columns (Foundation\Concerns\Reversible): a wrong
 * one is reversed through the corrections engine and stays, marked.
 *
 * ⚠ No crop-season module (Joy's brief: keep it minimal). The season IS the
 * head: one head per season and plot, opened by the Owner in the chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->date('harvested_on');
            $table->foreignId('crop_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_paisa');
            $table->text('note')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            // The popup's key, made when it opens: a double click or a retry
            // finds the harvest the first click made instead of making two.
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'harvested_on']);
        });

        Schema::create('crop_harvest_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_harvest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->boolean('is_main')->default(false);
            // In the product's stock unit; what was typed and the factor that
            // turned it into this figure are frozen beside it (C-UOM-4).
            $table->decimal('quantity', 15, 4);
            $table->decimal('entered_quantity', 15, 4);
            $table->foreignId('entered_unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('conversion_factor', 20, 8);
            $table->unsignedBigInteger('cost_paisa');
            $table->decimal('unit_cost', 15, 4);
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('crop_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->date('written_off_on');
            $table->foreignId('crop_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_paisa');
            $table->text('reason_text');
            // waiting | approved | rejected
            $table->string('status', 20)->default('waiting');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_write_offs');
        Schema::dropIfExists('crop_harvest_lines');
        Schema::dropIfExists('crop_harvests');
    }
};
