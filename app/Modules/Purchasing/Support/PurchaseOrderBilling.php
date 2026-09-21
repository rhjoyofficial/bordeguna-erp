<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
                ->orderBy('bill_date')
                ->orderBy('id'),
        ];
    }

    /**
     * @return array{state: 'nothing_billed'|'owed'|'settled', billed: string, outstanding: string}
     */
    public static function summarise(PurchaseOrder $order): array
    {
        $live = $order->bills->reject(fn (SupplierBill $bill) => $bill->isReversed());

        $billed = Money::zero();
        $outstanding = Money::zero();

        foreach ($live as $bill) {
            $billed = $billed->plus(Money::ofTaka((string) $bill->total));
            $outstanding = $outstanding->plus(self::outstandingOf($bill));
        }

        return [
            'state' => match (true) {
                $live->isEmpty() => 'nothing_billed',
                $outstanding->minor > 0 => 'owed',
                default => 'settled',
            },
            'billed' => $billed->toTaka(),
            'outstanding' => $outstanding->toTaka(),
        ];
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
            ->plus(Money::ofTaka((string) ($bill->credited_sum ?? '0'))->negated());
    }
}
