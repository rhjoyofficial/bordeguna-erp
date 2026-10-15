<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Support\Carbon;

/**
 * The open documents Inventory keeps, counted ONCE here for the overview's
 * "Needs you" card and for the screens that list them (15 Oct 2026).
 *
 * ⚠ Each count is the same question its own screen answers: a request the
 * Requests tab shows as pending, a transfer the Transfers tab shows in
 * transit, a count still a draft, a run still a draft, a batch the expiry
 * screen lists as expired. One definition, so the card and the list behind it
 * cannot disagree.
 *
 * ⚠ Reads only. Nothing here moves stock or posts anything.
 */
final class OpenItems
{
    /** Stock requests nobody has decided yet (the approver's queue). */
    public static function stockRequestsWaiting(): int
    {
        return StockRequest::query()
            ->whereHas('approvalRequest', fn ($query) => $query->where('status', ApprovalStatus::Pending))
            ->count();
    }

    /** Stock on the road: dispatched, not yet marked received, counted in neither warehouse. */
    public static function transfersInTransit(): int
    {
        return StockTransfer::query()->where('status', TransferStatus::InTransit)->count();
    }

    /** Stock counts started and not yet completed or cancelled. */
    public static function countsInProgress(): int
    {
        return StockCount::query()->where('status', StockCountStatus::Draft)->count();
    }

    /** Production runs saved but not confirmed: they consume nothing until then. */
    public static function draftProductionRuns(): int
    {
        return ProductionRun::query()->where('status', ProductionRunStatus::Draft)->count();
    }

    /**
     * Expired batches still held: on hand and valued, no longer sellable, by
     * the expiry screen's own rule (good THROUGH the expiry date).
     */
    public static function expiredBatchesOnShelf(Carbon $today): int
    {
        return ExpiryWindow::batches($today)
            ->filter(fn (StockBatch $batch) => ExpiryWindow::isExpired($batch, $today))
            ->count();
    }
}
