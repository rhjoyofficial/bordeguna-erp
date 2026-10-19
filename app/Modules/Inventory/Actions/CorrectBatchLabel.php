<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 1 (19 Oct 2026, FINAL-PLAN example 2): the label data of
 * a batch typed wrong at the door, for the SAME physical goods. Honey on a
 * receipt typed expiry 03/2026 while the jar says 03/2028: FEFO picks it first
 * and tonight's job proposes writing good honey off.
 *
 * What it changes: batch number, manufacture date, expiry date. Nothing else:
 * no quantity, no cost, no movement (a different lot is a receipt Correct).
 *
 * The re-checks, in the same transaction:
 *  - FEFO reads `expiry_date` at every pick, so the new date is the new order;
 *  - a write-off proposal still WAITING for this batch as expired is closed
 *    when the batch is no longer expired, with a note saying why (rejected:
 *    the approval engine has no "withdrawn" state, PUR-006's way). An
 *    approved one already moved stock and is left alone.
 */
class CorrectBatchLabel
{
    private const FIELDS = ['batch_number', 'manufactured_date', 'expiry_date'];

    /**
     * @param  array{batch_number?: ?string, manufactured_date?: ?string, expiry_date?: ?string}  $changes
     * @return array{before: array<string, ?string>, after: array<string, ?string>, proposals_closed: int}
     */
    public function handle(StockBatch $batch, array $changes, User $by, string $reason): array
    {
        return DB::transaction(function () use ($batch, $changes, $by, $reason) {
            $batch = StockBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $before = $this->read($batch);
            $wanted = array_intersect_key($changes, array_flip(self::FIELDS));
            $batch->forceFill($wanted)->save();
            $after = $this->read($batch->fresh());

            $closed = 0;
            $expiry = $after['expiry_date'];

            if ($before['expiry_date'] !== $expiry && ($expiry === null || $expiry >= Carbon::today()->toDateString())) {
                $pending = InventoryAdjustment::query()
                    ->where('source_type', StockBatch::class)
                    ->where('source_id', $batch->id)
                    ->where('type', InventoryAdjustmentType::Expired->value)
                    ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Pending->value))
                    ->get();

                foreach ($pending as $proposal) {
                    $proposal->approvalRequest->reject($by, 'Withdrawn: the batch\'s expiry was corrected to '.($expiry ?? 'none').". {$reason}");
                    $closed++;
                }
            }

            return ['before' => $before, 'after' => $after, 'proposals_closed' => $closed];
        });
    }

    /** @return array<string, ?string> */
    private function read(StockBatch $batch): array
    {
        return [
            'batch_number' => $batch->batch_number,
            // ⚠ toDateString(), never the date cast's UTC serialisation.
            'manufactured_date' => $batch->manufactured_date?->toDateString(),
            'expiry_date' => $batch->expiry_date?->toDateString(),
        ];
    }
}
