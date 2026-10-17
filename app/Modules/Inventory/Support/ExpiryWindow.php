<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which held batches have expired, and which are "expiring soon" BY THEIR OWN
 * PRODUCT'S WINDOW.
 *
 * ⚠⚠ THERE IS NO ONE "SOON". Joy, 26 Sep 2026 (Q9): each product says how many
 * days before expiry it should warn (`shelf_life_warning_days`, 90 when left
 * blank). Honey that keeps two years and a fresh pickle are not warned about on
 * the same day. The expiry screen and the inventory dashboard's strip used a
 * flat 30 days, so the receipt warned about a batch at 90 days that the expiry
 * screen did not list until 30 (Slice 0, D7). Both now read this class, so the
 * strip's count and the screen's list cannot disagree.
 *
 * ⚠ A batch is good THROUGH its expiry date (the consumable scope's rule):
 * expired starts the day after, and "soon" includes the last day of the window.
 */
final class ExpiryWindow
{
    /**
     * Held batches (active, something left, dated) that have expired or are
     * inside their product's warning window, earliest first, product and
     * warehouse loaded.
     *
     * ⚠ The window is per product, so the database is asked for everything up
     * to the WIDEST window in use and each batch is then held to its own. Doing
     * the date arithmetic per row in SQL would differ between MySQL and SQLite.
     *
     * ⭐ Traceability S8 (17 Oct 2026): the Expiry screen can ask a flat
     * window instead, the owner's own question ("what expires in 30 days"):
     * `$within` days, or `self::ALL` for every dated batch held. Left null, each
     * product's own window applies, as the dashboard and Needs attention read.
     *
     * @return Collection<int, StockBatch>
     */
    public static function batches(Carbon $today, ?int $within = null): Collection
    {
        $widest = $within ?? max(Product::DEFAULT_SHELF_LIFE_WARNING_DAYS, (int) Product::query()->max('shelf_life_warning_days'));

        return StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->when($within !== self::ALL, fn ($query) => $query->whereDate('expiry_date', '<=', $today->copy()->addDays($widest)))
            ->with(['product:id,name,sku,shelf_life_warning_days', 'warehouse:id,name'])
            ->orderBy('expiry_date')
            ->get()
            ->filter(fn (StockBatch $batch) => self::isExpired($batch, $today)
                || $within === self::ALL
                || $batch->expiry_date->lte($today->copy()->addDays($within ?? self::warningDays($batch))))
            ->values();
    }

    /** `$within` meaning "every dated batch, however far off". */
    public const ALL = -1;

    public static function isExpired(StockBatch $batch, Carbon $today): bool
    {
        return $batch->expiry_date->lt($today);
    }

    /** The batch's product's window, or the default for a batch whose product is gone. */
    public static function warningDays(StockBatch $batch): int
    {
        return $batch->product?->shelfLifeWarningDays() ?? Product::DEFAULT_SHELF_LIFE_WARNING_DAYS;
    }
}
