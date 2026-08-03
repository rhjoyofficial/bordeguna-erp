<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Support\Carbon;

/**
 * Closes the gap between a subsystem's decimals and the ledger's whole paisa.
 *
 * ⚠⚠ INVENTORY CARRIES FOUR DECIMAL PLACES; THE LEDGER HOLDS TWO. A stock value
 * of 3,333.3333 taka cannot be posted exactly, and the fraction has to land
 * somewhere a human can see rather than being absorbed into whichever line was
 * computed last. Joy's instruction, 2026-08-03: route unavoidable rounding
 * differences to the dedicated account instead of silently absorbing them.
 *
 * ⚠ In practice the residual is usually ZERO, and that is by design rather than
 * by luck: every inventory journal rounds each cost layer once and derives its
 * contra side from the SUM of those rounded figures, so both sides are made of
 * the same numbers. The residual exists for the journals where the two sides are
 * computed independently - a supplier bill carrying its own total against
 * separately valued receipts, when that comparison arrives.
 *
 * Building the mechanism now and finding it idle is the right way round. The
 * alternative is discovering the need in a month that will not close.
 */
final class LedgerRounding
{
    /**
     * Appends a balancing line if, and only if, one is needed.
     *
     * @param  list<JournalLineDraft>  $lines
     * @return list<JournalLineDraft>
     */
    public static function balance(array $lines, Carbon $on, string $memo = 'Rounding'): array
    {
        $difference = Money::sum(array_map(fn (JournalLineDraft $line) => $line->amount, $lines));

        if ($difference->isZero()) {
            return $lines;
        }

        // ⚠ The residual is the NEGATIVE of the imbalance: if the lines total a
        // debit of 1 paisa too many, the balancing line is a credit.
        $lines[] = JournalLineDraft::signed(
            AccountMapping::resolve('accounting.rounding_difference', $on)->id,
            $difference->negated(),
            $memo,
        );

        return $lines;
    }
}
