<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Foundation\Models\Business;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out the VAT on a sales invoice and freezes it onto the lines.
 *
 * Runs once, inside RecordSalesInvoice's transaction, after every line exists
 * and the whole-invoice discount is known. While VAT is off (every business,
 * today) it writes nothing and returns zero, and the invoice is exactly what it
 * always was.
 *
 * ⚠⚠ THE ORDER OF THE ARITHMETIC IS THE RULE (plan D3):
 *
 *  1. A line's own discount is already off `line_net`.
 *  2. The whole-invoice discount (typed, or a coupon's share) is spread across
 *     the GOODS lines in proportion to `line_net`, the last line taking the
 *     remainder so the shares add up to the discount to the paisa. A delivery
 *     charge is never discounted, so it takes no share.
 *  3. VAT is calculated on what is left of each line, at that line's own rate:
 *     a zero-rated jar on a discounted invoice stays zero-rated, and a standard
 *     one is taxed on what the customer really pays for it.
 *
 * ⚠⚠ `tax_on_discount` EXISTS SO THE JOURNAL BALANCES WHEN PRICES INCLUDE VAT.
 * An inclusive discount of 115 is 100 of discount and 15 of VAT never charged.
 * Revenue is credited net of VAT and Discounts Allowed is debited net of VAT,
 * and the only way both can be exact is to know the VAT inside each share.
 */
final class ApplyInvoiceTax
{
    public function __construct(private readonly TaxCalculator $calculator) {}

    /**
     * @return Money the invoice's total VAT
     */
    public function handle(SalesInvoice $invoice, Money $discount, bool $pricesIncludeTax, Carbon $taxPoint): Money
    {
        $business = Business::withoutGlobalScopes()->find($invoice->business_id);

        if (! $this->calculator->appliesOn($business, $taxPoint)) {
            return Money::zero();
        }

        /** @var Collection<int, SalesInvoiceLine> $lines */
        $lines = $invoice->lines()->with('product:id,tax_class_id')->orderBy('id')->get();
        $shares = $this->discountShares($lines, $discount);
        $total = Money::zero();

        foreach ($lines as $line) {
            $classId = $line->isDeliveryCharge()
                ? $business->delivery_tax_class_id
                : $line->product?->tax_class_id;

            $rate = $this->calculator->rateFor($business, $classId, $taxPoint);
            $share = $shares[$line->id] ?? Money::zero();
            $base = Money::ofMinor(Money::ofTaka((string) $line->line_net)->minor - $share->minor);

            $split = $this->calculator->split($base, $rate, $pricesIncludeTax);
            $onDiscount = $pricesIncludeTax
                ? $this->calculator->split($share, $rate, true)->tax
                : Money::zero();

            $line->forceFill([
                'tax_class_id' => $classId,
                'tax_rate' => $split->rate,
                'tax_amount' => $split->tax->toTaka(),
                'prices_include_tax' => $pricesIncludeTax,
                'discount_share' => $share->toTaka(),
                'tax_on_discount' => $onDiscount->toTaka(),
            ])->save();

            $total = $total->plus($split->tax);
        }

        return $total;
    }

    /**
     * @param  Collection<int, SalesInvoiceLine>  $lines
     * @return array<int, Money> line id => its share of the discount
     */
    private function discountShares($lines, Money $discount): array
    {
        $goods = $lines->reject(fn (SalesInvoiceLine $line) => $line->isDeliveryCharge())
            ->filter(fn (SalesInvoiceLine $line) => Money::ofTaka((string) $line->line_net)->minor > 0)
            ->values();

        if ($discount->isZero() || $goods->isEmpty()) {
            return [];
        }

        $base = $goods->sum(fn (SalesInvoiceLine $line) => Money::ofTaka((string) $line->line_net)->minor);
        $shares = [];
        $given = 0;

        foreach ($goods as $index => $line) {
            $isLast = $index === $goods->count() - 1;
            $net = Money::ofTaka((string) $line->line_net)->minor;

            // The last line takes what is left, so the shares sum exactly.
            $minor = $isLast ? $discount->minor - $given : intdiv($discount->minor * $net, $base);
            $minor = min($minor, $net);

            $shares[$line->id] = Money::ofMinor($minor);
            $given += $minor;
        }

        // ⚠ A discount of nearly the whole bill can leave the last line a paisa
        // or two short of room. Whatever it could not take goes to the lines
        // that still have some, so the shares always add up to the discount.
        foreach ($goods as $line) {
            if ($given >= $discount->minor) {
                break;
            }

            $room = Money::ofTaka((string) $line->line_net)->minor - $shares[$line->id]->minor;
            $extra = min($room, $discount->minor - $given);

            $shares[$line->id] = Money::ofMinor($shares[$line->id]->minor + $extra);
            $given += $extra;
        }

        return $shares;
    }
}
