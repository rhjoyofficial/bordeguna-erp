<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\StockRequestStatus;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\StockRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApproveStockRequest
{
    public function __construct(
        private readonly ApplyStockAdjustment $applyStockAdjustment,
        private readonly ApplyStockTransfer $applyStockTransfer,
    ) {}

    public function handle(StockRequest $request, User $reviewedBy): StockRequest
    {
        if ($request->status !== StockRequestStatus::Pending) {
            throw new RuntimeException("Stock request #{$request->id} has already been reviewed.");
        }

        return DB::transaction(function () use ($request, $reviewedBy) {
            // The requester performed the physical count/movement; the reviewer
            // only approves it — both are distinct facts the activity log keeps.
            $requester = $request->requestedBy;
            $note = "Approved request #{$request->id}: {$request->reason_note}";

            $movements = $request->type === StockRequestType::Transfer
                ? $this->applyStockTransfer->handle(
                    product: $request->product,
                    source: $request->warehouse,
                    target: $request->targetWarehouse,
                    quantity: $request->quantity,
                    performedBy: $requester,
                    note: $note,
                )
                : collect([$this->applyStockAdjustment->handle(
                    product: $request->product,
                    warehouse: $request->warehouse,
                    direction: $request->direction,
                    quantity: $request->quantity,
                    performedBy: $requester,
                    note: $note,
                )])->flatten();

            $request->update([
                'status' => StockRequestStatus::Approved,
                'reviewed_by' => $reviewedBy->id,
                'reviewed_at' => now(),
                'resulting_movement_id' => $movements->first()?->id,
            ]);

            return $request;
        });
    }
}
