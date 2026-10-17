<?php

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Inventory\Actions\ReleaseReservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sends an authorised owner withdrawal back to draft (Phase 2, 17 Oct 2026):
 * the holds are released, the frozen costs and reference prices cleared, so
 * the next authorisation reserves and prices afresh. Posting calls this too
 * when the reserved batch is gone or re-costed (ruling Q1: never silently
 * recompute; the document returns for re-approval with the reason).
 */
class ReopenOwnerWithdrawal
{
    public function __construct(private readonly ReleaseReservation $release) {}

    public function handle(OwnerWithdrawal $withdrawal, ?string $reason = null): OwnerWithdrawal
    {
        if (! $withdrawal->isApproved()) {
            throw new RuntimeException("Owner withdrawal {$withdrawal->number} is ".strtolower($withdrawal->status->label()).', so there is nothing to send back.');
        }

        return DB::transaction(function () use ($withdrawal, $reason) {
            $withdrawal = OwnerWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if (! $withdrawal->isApproved()) {
                throw new RuntimeException("Owner withdrawal {$withdrawal->number} changed a moment ago.");
            }

            $this->release->handle($withdrawal);

            $withdrawal->lines()->update([
                'stock_reservation_id' => null,
                'stock_batch_id' => null,
                'unit_cost' => null,
                'cost_paisa' => 0,
                'reference_source' => null,
                'reference_price' => null,
                'reference_paisa' => null,
                'difference_paisa' => null,
            ]);

            $withdrawal->update([
                'status' => OwnerWithdrawalStatus::Draft,
                'authorised_by' => null,
                'authorised_at' => null,
                'authorisation_note' => null,
                'self_authorised' => false,
                'reapproval_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'cost_total_paisa' => 0,
                'reference_total_paisa' => null,
            ]);

            return $withdrawal->fresh(['lines']);
        });
    }
}
