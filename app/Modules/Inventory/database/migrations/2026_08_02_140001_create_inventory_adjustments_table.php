<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 3. ONE table for every inventory quantity or value correction.
     *
     * ⚠⚠ Before this there were two ways to change stock. A user holding
     * `inventory.stock.adjust` called `ApplyStockAdjustment` directly - stock
     * changed, movements were written, and NOTHING recorded that a decision had
     * been made, by whom, or when it applied. A user without the permission went
     * through `stock_requests` and got a record and an approval. Same outcome,
     * different evidence, and that divergence existed before a single new
     * adjustment type was added.
     *
     * Every correction is now a row, always, including auto-approved ones.
     */
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Why it happened. ⚠ Metadata and reporting only - the engine never
            // branches on it. See InventoryAdjustmentType.
            $table->string('type');
            // What happens. THIS drives the mechanism, and it is deliberately not
            // derived from the type: a count variance and a manual correction go
            // either way, and deriving it would put a match on type back in by
            // the side door.
            $table->string('direction');

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->foreignId('reason_id')->constrained()->restrictOnDelete();
            // Required on an increase. Suggested from the latest purchase cost
            // and overridable by the approver (C-SC-1) - never assigned without
            // review, because an increase creates inventory value out of nothing.
            $table->decimal('unit_cost', 15, 4)->nullable();

            // When the correction APPLIES, not when it was typed. Goes through
            // Inventory\Support\EffectiveDate, so it inherits the future check
            // and the period lock from slices 1 and 2.
            $table->timestamp('effective_date');

            // Captured at creation and used to route approval (§5). ⚠ For a
            // decrease this is an ESTIMATE: the true value is only settled when
            // FIFO picks the cost layers at approval. It decides who approves,
            // which is a routing decision, and routing on a good estimate is
            // correct. The posted value is recorded exactly, on the movements.
            $table->decimal('estimated_value', 15, 4)->nullable();

            $table->text('note')->nullable();

            // What produced this: a stock count line, the expiry sweep, or
            // nothing at all for a manual one.
            $table->nullableMorphs('source');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'type']);
            $table->index('effective_date');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // ⚠ One adjustment, MANY movements. A decrease spanning three cost
            // layers writes three, and `ApproveStockRequest` recorded only
            // `$movements->first()?->id` - the audit trail was already lossy
            // before anything new was added.
            $table->foreignId('adjustment_id')->nullable()->after('reverses_movement_id')
                ->constrained('inventory_adjustments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_id');
        });

        Schema::dropIfExists('inventory_adjustments');
    }
};
