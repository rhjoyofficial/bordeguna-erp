<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\ExpiryWindow;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/**
 * Low, out of, expiring and expired stock, by the same rules as the low-stock
 * and expiry screens (Product::stockLevelIsLow, ExpiryWindow), so the counts
 * match them. One item per kind, not per product: the screens list them.
 *
 * Expired stock is critical: it is food, and a batch past its date must not
 * reach a customer.
 */
class StockSource extends Source
{
    public function key(): string
    {
        return 'stock';
    }

    public function area(): string
    {
        return 'stock';
    }

    protected function permissions(): array
    {
        return ['inventory.reports.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $totals = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        $low = 0;
        $out = 0;

        foreach (Product::stocked()->where('is_active', true)->get(['id', 'low_stock_threshold']) as $product) {
            $total = (float) ($totals[$product->id] ?? 0);
            $threshold = $product->low_stock_threshold !== null ? (float) $product->low_stock_threshold : null;

            if ($total <= 0) {
                $out++;
            } elseif (Product::stockLevelIsLow($threshold, $total)) {
                $low++;
            }
        }

        $today = Carbon::today();
        $batches = ExpiryWindow::batches($today);
        $expired = $batches->filter(fn (StockBatch $batch) => ExpiryWindow::isExpired($batch, $today));
        $expiring = $batches->reject(fn (StockBatch $batch) => ExpiryWindow::isExpired($batch, $today));

        $items = [];

        if ($expired->isNotEmpty()) {
            $items[] = $this->item('expired', AttentionItem::CRITICAL, 'stock_expired', '{count} batches in stock are past their expiry date.',
                ['count' => $expired->count()], '/inventory/expiry',
                ['since' => $expired->min(fn (StockBatch $b) => $b->expiry_date), 'ownerRole' => 'Manager', 'count' => $expired->count()]);
        }

        if ($out > 0) {
            $items[] = $this->item('out', AttentionItem::HIGH, 'stock_out', '{count} products are out of stock.',
                ['count' => $out], '/inventory/low-stocks', ['ownerRole' => 'Manager', 'count' => $out]);
        }

        if ($low > 0) {
            $items[] = $this->item('low', AttentionItem::NORMAL, 'stock_low', '{count} products are at or under their low-stock line.',
                ['count' => $low], '/inventory/low-stocks', ['ownerRole' => 'Manager', 'count' => $low]);
        }

        if ($expiring->isNotEmpty()) {
            $items[] = $this->item('expiring', AttentionItem::NORMAL, 'stock_expiring', '{count} batches expire inside their warning window.',
                ['count' => $expiring->count()], '/inventory/expiry', ['ownerRole' => 'Manager', 'count' => $expiring->count()]);
        }

        return $items;
    }
}
