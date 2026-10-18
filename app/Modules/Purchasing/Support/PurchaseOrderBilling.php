<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Where a purchase order stands with the supplier's money, read from the books.
 *
 * ⚠⚠ THIS REPLACES A DROPDOWN. The order used to carry a hand-set
 * "Unpaid / Partially paid / Paid" status that nothing tied to a bill or a
 * payment, so it could read "Paid" while Accounts Payable owed the supplier in
 * full (SALES-UX-AUDIT #11). Every figure here is derived from the order's
 * supplier bills and their payment and credit-note allocations, the same
 * derivation SupplierBill::outstanding() uses, so it cannot disagree with the
 * payables ledger.
 *
 * ⚠ Reversed bills are left out: a reversed bill owes nothing and bills
 * nothing.
 */
final class PurchaseOrderBilling
{
    /**
     * The eager load that makes summarise() cost no extra queries per order.
     *
     * @return array<string, \Closure>
     */
    public static function eagerLoad(): array
    {
        return [
            'bills' => fn (HasMany $query) => $query
                ->select(['id', 'business_id', 'purchase_order_id', 'bill_number', 'bill_date', 'total', 'reversed_at'])
                ->withSum('allocations as paid_sum', 'amount')
                ->withSum('creditAllocations as credited_sum', 'amount')
                ->withSum('adjustmentAllocations as adjusted_sum', 'amount')
                // ⭐ Supplier discounts (ruling P3): shown on the billing card.
                ->withSum(['discounts as discounted_sum' => fn ($q) => $q->whereNull('reversed_at')], 'amount')
                ->orderBy('bill_date')
                ->orderBy('id'),
        ];
    }

    /**
     * @return array{state: 'nothing_billed'|'owed'|'settled', billed: string, outstanding: string, discounted: string}
     */
    public static function summarise(PurchaseOrder $order): array
    {
        $live = $order->bills->reject(fn (SupplierBill $bill) => $bill->isReversed());

        $billed = Money::zero();
        $outstanding = Money::zero();
        $discounted = Money::zero();

        foreach ($live as $bill) {
            $billed = $billed->plus(Money::ofTaka((string) $bill->total));
            $outstanding = $outstanding->plus(self::outstandingOf($bill));
            // ⭐ Trade and settlement discounts (ruling P3): part of what settled the bill, but not money paid.
            $sum = array_key_exists('discounted_sum', $bill->getAttributes()) ? $bill->discounted_sum : $bill->discounts()->whereNull('reversed_at')->sum('amount');
            $discounted = $discounted->plus(Money::ofTaka((string) ($sum ?: '0')));
        }

        // ⭐ Ruling P5 (19 Oct 2026): a bill covering several orders names no
        // single order, so it is counted here by ITS LINES for this order: what
        // they billed, and at most that much of what the bill still owes
        // (payments settle the bill as a whole, never one order's part of it).
        $shared = self::sharedBills($order);

        foreach ($shared as $bill) {
            $share = self::shareOf($bill, $order);
            $billed = $billed->plus($share);
            $outstanding = $outstanding->plus(Money::ofMinor(min($share->minor, max(0, $bill->outstanding()->minor))));
        }

        return [
            'state' => match (true) {
                $live->isEmpty() && $shared->isEmpty() => 'nothing_billed',
                $outstanding->minor > 0 => 'owed',
                default => 'settled',
            },
            'billed' => $billed->toTaka(),
            'outstanding' => $outstanding->toTaka(),
            'discounted' => $discounted->toTaka(),
        ];
    }

    /**
     * ⭐ Ruling P5: live bills that name no single order but bill lines of
     * this one (one supplier invoice across several orders).
     *
     * @return Collection<int, SupplierBill>
     */
    public static function sharedBills(PurchaseOrder $order): Collection
    {
        return SupplierBill::query()
            ->whereNull('purchase_order_id')
            ->whereNull('landed_cost_id')
            ->whereNull('reversed_at')
            ->whereHas('lines.purchaseOrderLine', fn ($line) => $line->where('purchase_order_id', $order->id))
            ->with('lines.purchaseOrderLine:id,purchase_order_id')
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get();
    }

    /** What a shared bill's lines for this order come to. */
    public static function shareOf(SupplierBill $bill, PurchaseOrder $order): Money
    {
        return Money::sum($bill->lines
            ->filter(fn ($line) => (int) $line->purchaseOrderLine?->purchase_order_id === (int) $order->id)
            ->map(fn ($line) => Money::ofTaka((string) $line->line_total)));
    }

    /**
     * One bill's outstanding from the eager-loaded sums, falling back to the
     * model's own derivation when they were not loaded.
     */
    public static function outstandingOf(SupplierBill $bill): Money
    {
        if ($bill->isReversed()) {
            return Money::zero();
        }

        if (! array_key_exists('paid_sum', $bill->getAttributes())) {
            return $bill->outstanding();
        }

        return Money::ofTaka((string) $bill->total)
            ->plus(Money::ofTaka((string) ($bill->paid_sum ?? '0'))->negated())
            ->plus(Money::ofTaka((string) ($bill->credited_sum ?? '0'))->negated())
            ->plus(Money::ofTaka((string) ($bill->adjusted_sum ?? '0'))->negated());
    }
}
