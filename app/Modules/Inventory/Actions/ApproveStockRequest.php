<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\StockRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApproveStockRequest
{
    public function __construct(
        private readonly DispatchStockTransfer $dispatchStockTransfer,
    ) {}

    public function handle(StockRequest $request, User $reviewedBy): StockRequest
    {
        if (! $request->approvalRequest->isPending()) {
            throw new RuntimeException("Stock request #{$request->id} has already been reviewed.");
        }

        return DB::transaction(function () use ($request, $reviewedBy) {
            // The requester performed the physical count/movement; the reviewer
            // only approves it — both are distinct facts the activity log keeps.
            $requester = $request->approvalRequest->requestedBy;
            $note = "Approved request #{$request->id}".($request->note ? ": {$request->note}" : '');

            $transfer = $this->dispatchStockTransfer->handle(
                product: $request->product,
                source: $request->warehouse,
                target: $request->targetWarehouse,
                quantity: $request->quantity,
                reason: $request->reason,
                performedBy: $requester,
                note: $note,
            );

            $request->update(['resulting_transfer_id' => $transfer->id]);

            $request->approvalRequest->approve($reviewedBy);

            return $request;
        });
    }
}
