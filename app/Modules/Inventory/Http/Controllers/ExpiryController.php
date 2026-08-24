<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expired and expiring stock (DreamsPOS gap INV-3), per BATCH - the reference
 * template dates the PRODUCT, but a food business's expiry lives on the batch:
 * two receipts of the same shutki expire on two different days.
 *
 * Read-only on purpose. The FIFO scope already refuses to consume expired
 * stock by DATE (C-INV-3), and the nightly job
 * `inventory:propose-expired-write-offs` already proposes the write-off
 * adjustments; this screen is where a human sees it coming instead of finding
 * out from a refusal.
 */
class ExpiryController extends Controller
{
    /** How far ahead "expiring soon" looks, in days. */
    public const SOON_DAYS = 30;

    public function index(): Response
    {
        $this->authorize('inventory.reports.view');

        $today = Carbon::today();

        $batches = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $today->copy()->addDays(self::SOON_DAYS))
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->orderBy('expiry_date')
            ->get()
            ->map(function (StockBatch $batch) use ($today) {
                // ⚠ A batch is good THROUGH its expiry date (the consumable
                // scope's own rule) - expired starts the day AFTER.
                $expired = $batch->expiry_date->lt($today);

                return [
                    'id' => $batch->id,
                    'product' => $batch->product?->name,
                    'sku' => $batch->product?->sku,
                    'batch_number' => $batch->batch_number,
                    'warehouse' => $batch->warehouse?->name,
                    'quantity' => (float) $batch->current_quantity,
                    'expiry_date' => $batch->expiry_date->toDateString(),
                    'expired' => $expired,
                    // ⚠ Carbon diffInDays returns a float; cast where produced.
                    'days' => (int) abs($today->diffInDays($batch->expiry_date, false)),
                ];
            });

        return Inertia::render('inventory/expiry/index', [
            'expired' => $batches->filter(fn (array $row) => $row['expired'])->values(),
            'expiring' => $batches->reject(fn (array $row) => $row['expired'])->values(),
            'soon_days' => self::SOON_DAYS,
        ]);
    }
}
