<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrow landed cost (Phase 1, 17 Oct 2026, ChatGPT ruling Q4 to Q6).
 *
 * Before this, a cost that arrived beside or after a goods receipt (packaging,
 * freight, unloading labour, a bank charge) had nowhere to go but an expense
 * voucher, so packaging that the Owner's policy capitalises was expensed, and
 * the batch revaluation primitive (A1) was reachable from no screen.
 *
 *   landed_costs             the document: one goods receipt, a date, a status
 *   landed_cost_lines        each cost: type, treatment (seed default or an
 *                            override with its reason), allocation method PER
 *                            LINE, amount in paisa, payee (supplier OR money
 *                            account), the one receipt line for a direct line
 *   landed_cost_allocations  per batch evidence written at posting: the
 *                            quantities the split was made from, the share,
 *                            its on-hand and consumed parts, the restated
 *                            cost and the revaluation movement
 *
 * ⚠ Money is integer paisa (Money), never DECIMAL(15,2). Quantities and unit
 * costs are DECIMAL(15,4) like every stock table. Additive: nothing existing
 * changes. MySQL 8 and SQLite alike (plain string columns for the enums, as
 * the other purchasing tables do).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // LC-2026-27-0001, restarting each fiscal year (DocumentNumber).
            $table->string('number', 32);
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            // The day the cost was incurred: the posting date of the journal and
            // of the revaluation movements. Never before the receipt.
            $table->date('incurred_on');
            $table->text('note')->nullable();
            $table->bigInteger('total_paisa')->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('landed_cost_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landed_cost_id')->constrained('landed_costs')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('cost_type', 16);
            $table->string('treatment', 16);
            // Required when the treatment is not the type's seed default.
            $table->text('override_reason')->nullable();
            $table->string('allocation_method', 16)->default('value');
            // The one receipt line a `direct` allocation lands on.
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->bigInteger('amount_paisa');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            // The expense head of an expensed line when the type has no seeded
            // head (Other), or the person named one.
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landed_cost_line_id')->constrained('landed_cost_lines')->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('quantity_received', 15, 4);
            $table->decimal('quantity_on_hand', 15, 4);
            $table->decimal('quantity_consumed', 15, 4);
            $table->bigInteger('share_paisa');
            $table->bigInteger('on_hand_share_paisa');
            $table->bigInteger('consumed_share_paisa');
            $table->decimal('new_unit_cost', 15, 4)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('landed_cost_lines');
        Schema::dropIfExists('landed_costs');
    }
};
