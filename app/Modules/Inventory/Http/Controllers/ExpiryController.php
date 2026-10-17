<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\ExpiryWindow;
use Illuminate\Http\Request;
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
 *
 * ⚠ "Expiring soon" is each product's own warning window (90 days unless the
 * product says otherwise), the same rule the goods receipt warns by. It was a
 * flat 30 days until Slice 0 (D7).
 */
class ExpiryController extends Controller
{
    /** ⭐ Traceability S8 (17 Oct 2026): the windows the Expiry screen offers. */
    private const WINDOWS = ['30', '60', '90', 'all'];

    public function index(Request $request): Response
    {
        $this->authorize('inventory.reports.view');

        $today = Carbon::today();

        // ⚠ "Soon" is each product's own warning window, not one number for the
        // whole shop (Slice 0, D7): see ExpiryWindow. A chosen window (30, 60,
        // 90 days or all) overrides it on this screen only.
        $within = in_array($request->string('within')->toString(), self::WINDOWS, true) ? $request->string('within')->toString() : '';

        $batches = ExpiryWindow::batches($today, match ($within) {
            '' => null,
            'all' => ExpiryWindow::ALL,
            default => (int) $within,
        })
            ->map(function (StockBatch $batch) use ($today) {
                // ⚠ A batch is good THROUGH its expiry date (the consumable
                // scope's own rule) - expired starts the day AFTER.
                $expired = ExpiryWindow::isExpired($batch, $today);

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
                    'warning_days' => ExpiryWindow::warningDays($batch),
                    // Traceability M2: a held batch says so on the list.
                    'held' => $batch->isHeld(),
                ];
            });

        return Inertia::render('inventory/expiry/index', [
            'expired' => $batches->filter(fn (array $row) => $row['expired'])->values(),
            'expiring' => $batches->reject(fn (array $row) => $row['expired'])->values(),
            'default_warning_days' => Product::DEFAULT_SHELF_LIFE_WARNING_DAYS,
            'within' => $within,
        ]);
    }
}
