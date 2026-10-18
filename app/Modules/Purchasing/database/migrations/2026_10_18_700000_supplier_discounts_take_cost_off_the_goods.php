<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Supplier discounts (ChatGPT ruling P3, 18 Oct 2026): a discount the
 * supplier gives on goods is a reduction of what the goods COST, not income.
 *
 *   supplier_discounts             SD-2026-27-0001: trade (printed on the
 *                                  supplier's bill) or settlement (taken when
 *                                  paying, with evidence), the bill it
 *                                  reduces, the supplier's reference, the
 *                                  reason, the split it posted, its journal
 *                                  and the party adjustment that lowers the
 *                                  bill's outstanding
 *   supplier_discount_allocations  batch by batch: the share, the on-hand
 *                                  part (the batch's cost restated down) and
 *                                  the consumed part (cost of sales credited)
 *
 * Posting: Dr 2110 Accounts Payable (party = supplier) / Cr 1150 Stock on
 * Hand (on-hand share, with a value-only movement per batch) / Cr the
 * consumed-share head (purchasing.discount_consumed).
 *
 * ⚠ The bill's outstanding is lowered through a party adjustment of kind
 * `supplier_trade_discount` / `supplier_settlement_discount`, so every reader
 * that already knows adjustments (outstanding, ageing, cash forecast, the
 * cockpit, the subledger reconciliation) reads a discount without learning a
 * new table: the pattern a set-off uses. The journal belongs to the
 * discount, never to the adjustment.
 *
 * Prospective only. MySQL 8 and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            // trade | settlement
            $table->string('kind', 20);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('discount_on');
            $table->decimal('amount', 15, 4);
            // How it was typed: amount | percent (bill level) | lines (per line).
            $table->string('basis', 20)->default('amount');
            $table->decimal('percent', 8, 4)->nullable();
            // The supplier's own document, or the evidence of the agreement.
            $table->string('reference', 120);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('on_hand_paisa')->default(0);
            $table->unsignedBigInteger('consumed_paisa')->default(0);
            $table->foreignId('party_adjustment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // The Reversible columns, written by the corrections process only.
            $table->date('reversed_on')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'supplier_discounts_number_unique');
            $table->index(['supplier_id', 'reference']);
            $table->index('supplier_bill_id');
            $table->index('supplier_payment_id');
        });

        Schema::create('supplier_discount_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 15, 4);
            $table->decimal('quantity_on_hand', 15, 4);
            $table->decimal('quantity_consumed', 15, 4);
            $table->unsignedBigInteger('share_paisa');
            $table->unsignedBigInteger('on_hand_share_paisa');
            $table->unsignedBigInteger('consumed_share_paisa');
            $table->decimal('old_unit_cost', 15, 4);
            $table->decimal('new_unit_cost', 15, 4)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            // The movement that put the cost back, when the discount was reversed.
            $table->foreignId('reversal_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();

            $table->index('stock_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_discount_allocations');
        Schema::dropIfExists('supplier_discounts');
    }
};
