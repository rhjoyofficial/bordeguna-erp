<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\LandedCost;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Like ApprovePurchaseOrder: approving has no stock or ledger effect by
 * itself; it makes the document eligible for PostLandedCost. The status moves
 * to Approved only when the LAST stage said yes.
 */
class ApproveLandedCost
{
    public function handle(LandedCost $landedCost, User $reviewedBy, ?string $note = null): LandedCost
    {
        if ($landedCost->approvalRequest === null || ! $landedCost->approvalRequest->isPending()) {
            throw new RuntimeException("Landed cost {$landedCost->number} has already been reviewed.");
        }

        return DB::transaction(function () use ($landedCost, $reviewedBy, $note) {
            if ($landedCost->approvalRequest->approve($reviewedBy, $note)) {
                $landedCost->update(['status' => LandedCostStatus::Approved]);
            }

            return $landedCost->fresh(['approvalRequest', 'lines']);
        });
    }
}
