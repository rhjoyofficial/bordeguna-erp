<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Sales\Models\MarketPrice;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\ProductPriceHistory;
use App\Modules\Sales\Support\ProductPricing;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Publishes today's price of a market product (Phase 2 / 1B, 17 Oct 2026).
 *
 * ⚠⚠ THE ONLY WAY A MARKET PRICE GOES LIVE. A goods receipt PROPOSES; the
 * receiver CONFIRMS here, or the old price stays in force. A manual reprice
 * from the prices screen comes through here too. Nothing else writes a
 * market row into product_prices, so the ruling's gates (never below cost;
 * below the minimum margin only with a reason and the price-setting
 * permission) are checked once, at the chokepoint.
 *
 * What one confirmation writes, in one transaction:
 *   1. the previous market row in product_prices is closed (effective_to today);
 *   2. an immutable product_price_history row (cost, prior, suggested, new,
 *      the formula snapshot, the override reason, who, when);
 *   3. the new live product_prices row for RETAIL that the engine prices from;
 *   4. the market_prices row (one per product/channel) that carries "since".
 *
 * ⚠ Concurrency: the market_prices row is locked for update first, so two
 * receipts of the same fish confirmed together serialise; the last confirmed
 * is today's price and both are in the history.
 */
class ConfirmMarketPrice
{
    public function handle(
        Product $product,
        string $price,
        string $cost,
        User $by,
        ?StockBatch $batch = null,
        ?GoodsReceiptLine $receiptLine = null,
        ?string $reason = null,
        string $source = ProductPriceHistory::SOURCE_RECEIPT,
        mixed $at = null,
    ): ProductPriceHistory {
        if (! $product->isMarketPriced()) {
            throw new DomainException("{$product->name} is priced by its MRP and the price list, not from the market. Switch its pricing mode first.");
        }

        if (($refusal = ProductPricing::refusalFor($product, $price, $cost, $reason, $by)) !== null) {
            throw new InvalidArgumentException($refusal);
        }

        $at = $at instanceof Carbon ? $at : ($at === null ? Carbon::now() : Carbon::parse($at));
        $rules = ProductPricing::rulesFor($product);
        $suggested = ProductPricing::suggest($cost, $rules['markup'], $rules['min_margin'], $rules['step']);
        $price = bcadd($price, '0', 4);
        $reason = trim((string) $reason) === '' ? null : trim((string) $reason);

        return DB::transaction(function () use ($product, $price, $cost, $by, $batch, $receiptLine, $reason, $source, $at, $rules, $suggested) {
            $current = MarketPrice::query()
                ->withoutGlobalScopes()
                ->where('business_id', $product->business_id)
                ->where('product_id', $product->id)
                ->where('channel', MarketPrice::CHANNEL_RETAIL)
                ->lockForUpdate()
                ->first();

            $retailGroupId = PriceGroup::query()
                ->withoutGlobalScopes()
                ->where('business_id', $product->business_id)
                ->where('code', PriceGroup::DEFAULT_CODE)
                ->value('id');

            // ⚠ Closed TODAY, not yesterday: a row confirmed this morning has
            // effective_from today and cannot end before it started. Both rows
            // are then in force today and the engine takes the newer id.
            ProductPrice::query()
                ->withoutGlobalScopes()
                ->where('business_id', $product->business_id)
                ->where('product_id', $product->id)
                ->whereNotNull('market_price_history_id')
                ->whereNull('effective_to')
                ->update(['effective_to' => $at->toDateString()]);

            $history = ProductPriceHistory::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'channel' => MarketPrice::CHANNEL_RETAIL,
                'price_group_id' => $retailGroupId,
                'source' => $source,
                'goods_receipt_line_id' => $receiptLine?->id,
                'stock_batch_id' => $batch?->id,
                'cost_snapshot' => $cost,
                'prior_price' => $current?->unit_price,
                'suggested_price' => $suggested,
                'new_price' => $price,
                'markup_percent' => $rules['markup'],
                'min_margin_percent' => $rules['min_margin'],
                'rounding_step' => $rules['step'],
                'is_override' => bccomp($price, $suggested, 4) !== 0,
                'override_reason' => $reason,
                'confirmed_by' => $by->id,
                'confirmed_at' => $at,
            ]);

            $live = ProductPrice::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'price_group_id' => $retailGroupId,
                'min_quantity' => '0',
                'unit_price' => $price,
                'price_method' => 'market',
                'effective_from' => $at->toDateString(),
                'note' => $source === ProductPriceHistory::SOURCE_RECEIPT
                    ? "Today's price, set at goods receipt"
                    : "Today's price, set by hand",
                'created_by' => $by->id,
                'market_price_history_id' => $history->id,
            ]);

            $history->update(['product_price_id' => $live->id]);

            MarketPrice::query()->withoutGlobalScopes()->updateOrCreate(
                ['business_id' => $product->business_id, 'product_id' => $product->id, 'channel' => MarketPrice::CHANNEL_RETAIL],
                [
                    'unit_price' => $price,
                    'cost_snapshot' => $cost,
                    'product_price_id' => $live->id,
                    'product_price_history_id' => $history->id,
                    'confirmed_by' => $by->id,
                    'since_at' => $at,
                ],
            );

            $receiptLine?->update([
                'market_price_status' => ProductPricing::STATUS_CONFIRMED,
                'market_price_history_id' => $history->id,
            ]);

            return $history;
        });
    }
}
