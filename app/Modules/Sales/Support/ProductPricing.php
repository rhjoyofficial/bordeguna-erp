<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Enums\PricingMode;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\MarketPrice;
use Illuminate\Support\Carbon;

/**
 * Market pricing: the reference price other modules read, today's price, the
 * cost basis a price is set against, and the one gate a typed price passes
 * (Phase 2 / 1B, 17 Oct 2026; rule rewritten 18 Oct 2026).
 *
 * ⭐⭐ 18 Oct 2026 (Joy, DECISIONS-JOY-2026-10-18 D-E, superseding the
 * markup / min-margin / suggestion parts of ChatGPT REPLY-2): NO FORMULA AND
 * NO SUGGESTION in Phase 1. The authorised person types today's price while
 * seeing the batch cost (per stock unit) and the previous active price. The
 * markup, minimum margin and rounding step columns on products and
 * categories stay in the database and are ignored.
 *
 * ⚠ WHY below cost is no longer refused: fish that goes soft by evening must
 * be cleared, and a refusal pushed staff to sell off the system. Below cost is
 * now a MARKDOWN: allowed, with a warning, a reason and the
 * `sales.prices.markdown` permission (Owner and Manager by default).
 *
 * ⚠ bcmath throughout, scale 6, finished to four places like every price in
 * product_prices.
 */
final class ProductPricing
{
    private const SCALE = 6;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNCONFIRMED = 'unconfirmed';

    /** The cap reference is a printed MRP. */
    public const LABEL_MRP = 'mrp';

    /** The cap reference is the selling price in force (market or list), never called MRP. */
    public const LABEL_REFERENCE = 'reference';

    /**
     * The price a staff-purchase cap is measured at (Builder E reads this):
     * the MRP for a fixed_mrp product, today's confirmed selling price for a
     * market product. Null when the product has neither, which the caller
     * treats as "cannot be priced", never as zero.
     */
    public static function capReferencePrice(Product $product): ?string
    {
        if ($product->isMarketPriced()) {
            $current = self::currentMarketPrice($product);

            return $current === null ? null : (string) $current->unit_price;
        }

        return $product->mrp !== null && (float) $product->mrp > 0 ? (string) $product->mrp : null;
    }

    /**
     * ⭐ The CAP REFERENCE PRICE with its label (ChatGPT ruling Q3, Phase 2,
     * 17 Oct 2026): the figure the staff monthly allowance is measured at and
     * the owner withdrawal keeps beside its cost, frozen on every line.
     *
     *  - market product: today's confirmed price, labelled `reference`; none
     *    confirmed means null (a market product never falls back to its MRP);
     *  - fixed_mrp product with a printed MRP: the MRP, labelled `mrp`;
     *  - fixed_mrp product without one: the selling price in force for the
     *    walk-in customer on `$on`, labelled `reference`;
     *  - nothing in force: null, and the caller refuses the product.
     *
     * One definition for builders E (staff purchase) and F (owner withdrawal),
     * so the two documents cannot disagree on what "reference" means.
     *
     * @return array{amount: string, label: string}|null amount in taka (2dp)
     */
    public static function capReference(Product $product, ?Carbon $on = null): ?array
    {
        if ($product->isMarketPriced()) {
            $current = self::capReferencePrice($product);

            return $current === null ? null : ['amount' => number_format((float) $current, 2, '.', ''), 'label' => self::LABEL_REFERENCE];
        }

        if ((float) ($product->mrp ?? 0) > 0) {
            return ['amount' => number_format((float) $product->mrp, 2, '.', ''), 'label' => self::LABEL_MRP];
        }

        $resolved = app(ResolveSellingPrice::class)->find($product, Customer::walkIn(), '1', $on ?? Carbon::now());

        if ($resolved === null) {
            return null;
        }

        return ['amount' => number_format((float) $resolved->unitPrice, 2, '.', ''), 'label' => self::LABEL_REFERENCE];
    }

    public static function currentMarketPrice(Product $product, string $channel = MarketPrice::CHANNEL_RETAIL): ?MarketPrice
    {
        return MarketPrice::query()
            ->withoutGlobalScopes()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->where('channel', $channel)
            ->first();
    }

    /** Gross margin on the selling price, in percent, two places. Null when the price is zero. */
    public static function marginPercent(string $cost, string $price): ?string
    {
        if (bccomp($price, '0', self::SCALE) <= 0) {
            return null;
        }

        return bcdiv(bcmul(bcsub($price, $cost, self::SCALE), '100', self::SCALE), $price, 2);
    }

    /** The permission that lets a price go below the batch cost (a markdown). */
    public const MARKDOWN_PERMISSION = 'sales.prices.markdown';

    /**
     * The cost basis a manual price is set against when nobody picked a
     * batch: the newest batch still ON HAND, else the newest ever received.
     * Null when nothing was ever received: a market product with no cost
     * basis has no price and cannot be sold.
     *
     * @return array{cost: string, batch_id: int}|null
     */
    public static function latestCostBasis(Product $product): ?array
    {
        $base = StockBatch::query()
            ->withoutGlobalScopes()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->orderByDesc('id');

        $batch = (clone $base)->where('current_quantity', '>', 0)->first(['id', 'unit_cost'])
            ?? $base->first(['id', 'unit_cost']);

        return $batch === null ? null : ['cost' => (string) $batch->unit_cost, 'batch_id' => (int) $batch->id];
    }

    /**
     * ⭐ 18 Oct 2026 (Joy, D-E): the batches on hand a price may be set from on
     * Sales setup > Selling prices, newest first.
     *
     * @return list<array{id: int, batch_number: ?string, cost: string, on_hand: string, received_on: ?string}>
     */
    public static function batchesOnHand(Product $product): array
    {
        return StockBatch::query()
            ->withoutGlobalScopes()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->where('current_quantity', '>', 0)
            ->orderByDesc('id')
            ->get(['id', 'batch_number', 'unit_cost', 'current_quantity', 'created_at'])
            ->map(fn (StockBatch $batch) => [
                'id' => (int) $batch->id,
                'batch_number' => $batch->batch_number,
                'cost' => bcadd((string) $batch->unit_cost, '0', 4),
                'on_hand' => (string) $batch->current_quantity,
                'received_on' => $batch->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Everything a "Set today's price" panel shows for one product at one
     * batch cost: the cost, the previous active price with its since date,
     * and what the price box is prefilled with (the previous price, or
     * empty). ⚠ No suggestion (Joy, 18 Oct 2026, D-E).
     *
     * @return array<string, mixed>
     */
    public static function proposal(Product $product, string $cost): array
    {
        $current = self::currentMarketPrice($product);

        return [
            'cost' => bcadd($cost, '0', 4),
            'current_price' => $current === null ? null : (string) $current->unit_price,
            'current_since' => $current?->sinceLabel(),
            'current_since_at' => $current?->since_at?->toDateTimeString(),
            'current_since_date' => $current?->since_at?->toDateString(),
            'prefill' => $current === null ? null : self::plain((string) $current->unit_price),
        ];
    }

    /** Whether a price is a markdown: under the batch cost. */
    public static function isMarkdown(string $price, string $cost): bool
    {
        return is_numeric($price) && bccomp($price, $cost, 4) < 0;
    }

    /**
     * Why this price may not be confirmed, or null if it may.
     *
     * ⭐ 18 Oct 2026 (Joy, D-E): at or above cost, free. Below cost is a
     * markdown: it needs `sales.prices.markdown` AND a reason; without the
     * permission it is refused with the same warning the screen shows.
     *
     * ⚠ Called by the screen (to land the sentence under the right box) AND
     * by ConfirmMarketPrice (the chokepoint), so the two cannot disagree.
     */
    public static function refusalFor(Product $product, string $price, string $cost, ?string $reason, User $by): ?string
    {
        if (! is_numeric($price) || bccomp($price, '0', self::SCALE) <= 0) {
            return "Type today's price for {$product->name}.";
        }

        if (! self::isMarkdown($price, $cost)) {
            return null;
        }

        $warning = self::markdownWarning($cost);

        if (! $by->can(self::MARKDOWN_PERMISSION)) {
            return "{$warning} Your role may not mark a price down; ask the Owner or a Manager.";
        }

        if (trim((string) $reason) === '') {
            return $warning;
        }

        return null;
    }

    /** "Below the batch cost of Tk 123.45: a markdown. Reason required." */
    public static function markdownWarning(string $cost): string
    {
        return sprintf('Below the batch cost of Tk %s: a markdown. Reason required.', self::plain(bcadd($cost, '0', 4)));
    }

    /** "170.0000" as "170", "164.6000" as "164.6": what a person reads. */
    public static function plain(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    public static function modeOf(Product $product): PricingMode
    {
        return $product->pricingMode();
    }
}
