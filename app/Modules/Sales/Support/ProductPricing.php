<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Enums\PricingMode;
use App\Modules\Sales\Models\MarketPrice;

/**
 * Market pricing arithmetic and the reference price other modules read
 * (Phase 2 / 1B, 17 Oct 2026; ChatGPT ruling Q4).
 *
 * ⚠⚠ THE DEFINITIONS, in one place, so that two developers cannot build two
 * formulas from the same three field names:
 *
 *   markup_percent (m)      markup ON COST:            C x (1 + m/100)
 *   min_margin_percent (g)  gross margin ON SELLING:   C / (1 - g/100)
 *   rounding_step           the price increment, Tk 1 / 5 / 10, rounded UP
 *
 *   suggested = roundUpToStep( max( C x (1 + m/100), C / (1 - g/100) ) )
 *
 * where C is the received batch unit cost per STOCK unit. The second term is
 * left out when g is null. Rounding is always UP to the step, never to the
 * nearest, so the rounded price never undercuts the margin the rule asked
 * for. Worked example the tests pin: C 123.45, m 20, g 25, step 10 gives
 * max(148.14, 164.60) = 164.60, rounded up to 170.
 *
 * ⚠ bcmath throughout, scale 6, finished to four places like every price in
 * product_prices. Floats would make 164.60 / 10 = 16.460000000000001 and the
 * ceiling would be wrong by a whole step.
 */
final class ProductPricing
{
    private const SCALE = 6;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNCONFIRMED = 'unconfirmed';

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

    public static function currentMarketPrice(Product $product, string $channel = MarketPrice::CHANNEL_RETAIL): ?MarketPrice
    {
        return MarketPrice::query()
            ->withoutGlobalScopes()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->where('channel', $channel)
            ->first();
    }

    /**
     * The rules in force for this product: its own override where set, else
     * the category's default, else nothing. Each of the three is resolved on
     * its own, so a SKU can override only the step and inherit the rest.
     *
     * @return array{markup: ?string, min_margin: ?string, step: ?string, source: array<string, string>}
     */
    public static function rulesFor(Product $product): array
    {
        $product->loadMissing('category');
        $category = $product->category;
        $rules = ['source' => []];

        foreach (['markup' => 'market_markup_percent', 'min_margin' => 'market_min_margin_percent', 'step' => 'market_rounding_step'] as $key => $column) {
            if ($product->{$column} !== null) {
                $rules[$key] = (string) $product->{$column};
                $rules['source'][$key] = 'product';
            } elseif ($category !== null && $category->{$column} !== null) {
                $rules[$key] = (string) $category->{$column};
                $rules['source'][$key] = 'category';
            } else {
                $rules[$key] = null;
                $rules['source'][$key] = 'none';
            }
        }

        return $rules;
    }

    /**
     * The suggested price for a cost, four places. See the class docblock.
     */
    public static function suggest(string $cost, ?string $markup, ?string $minMargin, ?string $step): string
    {
        $best = self::markupPrice($cost, $markup);
        $floor = self::minMarginPrice($cost, $minMargin, roundUp: false);

        if ($floor !== null && bccomp($floor, $best, self::SCALE) > 0) {
            $best = $floor;
        }

        return self::roundUpToStep($best, $step);
    }

    /** C x (1 + m/100), unrounded (scale 6). A null markup is 0%. */
    public static function markupPrice(string $cost, ?string $markup): string
    {
        $factor = bcdiv(bcadd('100', $markup ?? '0', self::SCALE), '100', self::SCALE);

        return bcmul($cost, $factor, self::SCALE);
    }

    /**
     * C / (1 - g/100): the lowest price that still earns the minimum gross
     * margin on the selling price. Null when no minimum is set. ⚠ g of 100 or
     * more would divide by zero or go negative; validation refuses it, and
     * this guards again.
     */
    public static function minMarginPrice(string $cost, ?string $minMargin, bool $roundUp = true): ?string
    {
        if ($minMargin === null || bccomp($minMargin, '0', self::SCALE) <= 0) {
            return null;
        }

        if (bccomp($minMargin, '100', self::SCALE) >= 0) {
            return null;
        }

        $divisor = bcdiv(bcsub('100', $minMargin, self::SCALE), '100', self::SCALE);
        $price = bcdiv($cost, $divisor, self::SCALE);

        return $roundUp ? self::roundUpToStep($price, '0.01') : $price;
    }

    /**
     * The smallest multiple of the step that is not below the amount. No
     * step (null or zero) rounds up to the paisa.
     */
    public static function roundUpToStep(string $amount, ?string $step): string
    {
        if ($step === null || bccomp($step, '0', self::SCALE) <= 0) {
            $step = '0.01';
        }

        $quotient = bcdiv($amount, $step, self::SCALE);
        [$whole, $fraction] = array_pad(explode('.', $quotient, 2), 2, '');
        $steps = ltrim($fraction, '0') === '' ? $whole : bcadd($whole, '1', 0);

        return bcadd(bcmul($steps, $step, self::SCALE), '0', 4);
    }

    /** Gross margin on the selling price, in percent, two places. Null when the price is zero. */
    public static function marginPercent(string $cost, string $price): ?string
    {
        if (bccomp($price, '0', self::SCALE) <= 0) {
            return null;
        }

        return bcdiv(bcmul(bcsub($price, $cost, self::SCALE), '100', self::SCALE), $price, 2);
    }

    /**
     * What the latest received lot of this product cost per stock unit, and
     * which batch that was. Null when nothing was ever received: a market
     * product with no cost basis has no price and cannot be sold.
     *
     * @return array{cost: string, batch_id: int}|null
     */
    public static function latestCostBasis(Product $product): ?array
    {
        $batch = StockBatch::query()
            ->withoutGlobalScopes()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->first(['id', 'unit_cost']);

        return $batch === null ? null : ['cost' => (string) $batch->unit_cost, 'batch_id' => (int) $batch->id];
    }

    /**
     * Everything a "Set today's price" panel shows for one product at one
     * cost: the suggestion, the floor, the margin line, the rules and today's
     * price if there is one.
     *
     * @return array<string, mixed>
     */
    public static function proposal(Product $product, string $cost): array
    {
        $rules = self::rulesFor($product);
        $suggested = self::suggest($cost, $rules['markup'], $rules['min_margin'], $rules['step']);
        $current = self::currentMarketPrice($product);

        return [
            'cost' => bcadd($cost, '0', 4),
            'suggested' => $suggested,
            'suggested_margin_percent' => self::marginPercent($cost, $suggested),
            'min_margin_price' => self::minMarginPrice($cost, $rules['min_margin']),
            'markup_percent' => $rules['markup'],
            'min_margin_percent' => $rules['min_margin'],
            'rounding_step' => $rules['step'],
            'rule_source' => $rules['source'],
            'current_price' => $current === null ? null : (string) $current->unit_price,
            'current_since' => $current?->sinceLabel(),
            'current_since_at' => $current?->since_at?->toDateTimeString(),
        ];
    }

    /**
     * Why this price may not be confirmed, or null if it may. The ruling's
     * three gates: below cost is refused outright; below the minimum margin
     * needs a reason and the price-setting permission; otherwise free.
     *
     * ⚠ Called by the screen (to land the sentence under the right box) AND
     * by ConfirmMarketPrice (the chokepoint), so the two cannot disagree.
     */
    public static function refusalFor(Product $product, string $price, string $cost, ?string $reason, User $by): ?string
    {
        if (! is_numeric($price) || bccomp($price, '0', self::SCALE) <= 0) {
            return "Type today's price for {$product->name}.";
        }

        if (bccomp($price, $cost, 4) < 0) {
            return sprintf('Tk %s is below what %s cost to buy (Tk %s). A market product is never sold below cost.', self::plain($price), $product->name, self::plain($cost));
        }

        $rules = self::rulesFor($product);
        $floor = self::minMarginPrice($cost, $rules['min_margin']);

        if ($floor !== null && bccomp($price, $floor, 4) < 0) {
            if (! $by->can('sales.prices.manage')) {
                return sprintf('Tk %s is below the minimum margin of %s%% (Tk %s). Going under it needs the price-setting permission, which your role does not have.', self::plain($price), self::plain($rules['min_margin']), self::plain($floor));
            }

            if (trim((string) $reason) === '') {
                return sprintf('Tk %s is below the minimum margin of %s%% (Tk %s). Say why.', self::plain($price), self::plain($rules['min_margin']), self::plain($floor));
            }
        }

        return null;
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
