<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;

/**
 * Raises a pending write-off for every batch that has expired and still holds
 * stock (C-INV-3).
 *
 * ⚠⚠ It PROPOSES. It never posts. Joy's decision is that expired stock leaves
 * only through an authorised write-off, so this creates an ordinary
 * `InventoryAdjustment` and a human approves it - the same approval, the same
 * audit trail and the same movements as every other correction.
 *
 * ℹ Making stock unsellable is NOT this class's job and must never depend on it
 * having run. `StockBatch::scopeConsumable()` excludes expired batches by date,
 * so a customer is protected whether or not this ever executes.
 */
class ProposeExpiredStockWriteOffs
{
    public function __construct(
        private readonly CreateInventoryAdjustment $create,
    ) {}

    /**
     * @return int how many proposals were raised, across every business
     */
    public function handle(): int
    {
        // ⚠⚠ A scheduled run has NO BusinessContext, and StockBatch is
        // business-scoped - so without this the query would compile to `1 = 0`
        // and the sweep would find nothing, silently, every night. The unit
        // tests all passed because they had set a context; only running it cold
        // exposed it.
        //
        // Same family as W1: code that is correct inside a request and wrong
        // outside one. TenantScope is the one entry point, so it is used here
        // rather than setting the context by hand.
        if (app(BusinessContext::class)->hasBusiness()) {
            return $this->sweepCurrentBusiness();
        }

        $raised = 0;

        foreach (Business::where('is_active', true)->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);
            $raised += $this->sweepCurrentBusiness();
        }

        TenantScope::leave();

        return $raised;
    }

    private function sweepCurrentBusiness(): int
    {
        $actor = SystemActor::user();
        $raised = 0;

        $expired = StockBatch::where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($expired as $batch) {
            // ⚠ Idempotent per BATCH. A nightly job that stacks a fresh proposal
            // every run is the all-or-nothing seeder bug with a clock attached,
            // and this project has met that shape twice already.
            $alreadyProposed = InventoryAdjustment::where('source_type', StockBatch::class)
                ->where('source_id', $batch->id)
                ->whereHas('approvalRequest', fn ($q) => $q->where('status', 'pending'))
                ->exists();

            if ($alreadyProposed) {
                continue;
            }

            $this->create->handle(
                type: InventoryAdjustmentType::Expired,
                direction: AdjustmentDirection::Decrease,
                product: $batch->product,
                warehouse: $batch->warehouse,
                quantity: (string) $batch->current_quantity,
                reason: $this->reasonFor($batch),
                createdBy: $actor,
                note: "Batch #{$batch->id} expired on {$batch->expiry_date->toDateString()}",
                source: $batch,
            );

            $raised++;
        }

        return $raised;
    }

    /**
     * ⚠ Resolved rather than assumed. `InventoryReasonsSeeder` seeds "Expired",
     * but a business that has never run it would otherwise crash the sweep every
     * night on a foreign key.
     */
    private function reasonFor(StockBatch $batch): Reason
    {
        return Reason::firstOrCreate(
            ['business_id' => $batch->business_id, 'context' => 'inventory.stock_adjustment', 'name' => 'Expired'],
            ['is_active' => true],
        );
    }
}
