<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\SupplierLedger;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * ⭐ The supplier credit WARNING (ruling P6, 18 Oct 2026).
 *
 * What we stand to owe this supplier, in its parts, beside the credit limit
 * typed on the supplier:
 * - billed AP (2110, supplier-tagged): a liability in the books;
 * - received not billed (2120 GRNI, supplier-tagged, wave A): goods here, bill
 *   not yet;
 * - open purchase-order commitments: ordered, not yet received. ⚠ NOT a
 *   liability, and never presented as one; it counts only toward the
 *   committed figure the warning compares;
 * - advances (1160): what they hold of ours, SHOWN, never netted away. A
 *   general advance may not be available against this purchase, so the
 *   warning measures the gross figure (ruling P6: "do not silently net away
 *   risks").
 *
 * The figures are SupplierLedger's own cards, so this panel, the supplier
 * ledger and the statement cannot disagree.
 *
 * ⚠⚠ A WARNING, NEVER A BLOCK. Nothing anywhere refuses a purchase order or a
 * bill on this; the screen shows it and a person decides.
 */
final class SupplierCredit
{
    /** From this share of the limit (percent) the warning says "near". */
    public const NEAR_PERCENT = 80;

    public const LEVEL_NONE = 'none';

    public const LEVEL_OK = 'ok';

    public const LEVEL_NEAR = 'near';

    public const LEVEL_OVER = 'over';

    /**
     * @param  Money  $adding  what the document being entered adds to the
     *                         committed figure (a new order's total; zero for
     *                         a bill of goods already received, which only
     *                         moves "received not billed" to "billed")
     * @return array<string, mixed>
     */
    public static function for(Supplier $supplier, ?Money $adding = null, ?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $adding ??= Money::zero();
        $ledger = SupplierLedger::for($supplier, $on, $on);

        $billed = self::owed($ledger->billedPayable());
        $unbilled = self::owed($ledger->receivedNotBilled());
        $committed = self::owed($ledger->openCommitments());
        $advances = self::owed($ledger->advances());

        $exposure = Money::ofMinor($billed->minor + $unbilled->minor + $committed->minor);
        $after = Money::ofMinor($exposure->minor + max(0, $adding->minor));
        $limit = $supplier->credit_limit === null ? null : Money::ofTaka((string) $supplier->credit_limit);

        return [
            'credit_limit' => $limit?->toTaka(),
            'billed' => $billed->toTaka(),
            'received_not_billed' => $unbilled->toTaka(),
            'open_orders' => $committed->toTaka(),
            'advances' => $advances->toTaka(),
            'exposure' => $exposure->toTaka(),
            'adding' => Money::ofMinor(max(0, $adding->minor))->toTaka(),
            'after' => $after->toTaka(),
            // Shown for information only; the warning never reads it.
            'after_less_advances' => Money::ofMinor($after->minor - $advances->minor)->toTaka(),
            'headroom' => $limit === null ? null : Money::ofMinor($limit->minor - $after->minor)->toTaka(),
            'level' => self::level($limit, $after),
            'near_percent' => self::NEAR_PERCENT,
            'terms' => PaymentTerms::forSupplier($supplier)?->toArray(),
        ];
    }

    /**
     * none: no limit set. ok: under NEAR_PERCENT of it. near: at or above that
     * share and not over. over: above the limit (reaching it exactly is near).
     */
    public static function level(?Money $limit, Money $after): string
    {
        if ($limit === null) {
            return self::LEVEL_NONE;
        }

        if ($after->minor > $limit->minor) {
            return self::LEVEL_OVER;
        }

        // ⚠ Integer comparison in paisa: after / limit >= 80 / 100.
        return $after->minor * 100 >= $limit->minor * self::NEAR_PERCENT ? self::LEVEL_NEAR : self::LEVEL_OK;
    }

    /** A credit balance on the payable side (we owe) reads as positive; nothing is shown below zero. */
    private static function owed(Money $money): Money
    {
        return Money::ofMinor(max(0, $money->minor));
    }
}
