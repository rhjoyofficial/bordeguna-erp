<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\StockRequestStatus;
use App\Modules\Inventory\Models\StockRequest;
use RuntimeException;

class RejectStockRequest
{
    public function handle(StockRequest $request, User $reviewedBy, ?string $reviewNote = null): StockRequest
    {
        if ($request->status !== StockRequestStatus::Pending) {
            throw new RuntimeException("Stock request #{$request->id} has already been reviewed.");
        }

        $request->update([
            'status' => StockRequestStatus::Rejected,
            'reviewed_by' => $reviewedBy->id,
            'reviewed_at' => now(),
        ]);

        if ($reviewNote) {
            activity()
                ->performedOn($request)
                ->causedBy($reviewedBy)
                ->log("Rejected: {$reviewNote}");
        }

        return $request;
    }
}
