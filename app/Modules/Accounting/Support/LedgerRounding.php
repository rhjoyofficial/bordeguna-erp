<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Exceptions\JournalException;
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
     * The largest gap this class will name as rounding, in paisa.
     *
     * ⚠⚠ 14 Oct 2026 (audit #8): there was no ceiling. A bug that dropped a
     * Tk 50,000 line would have been booked quietly as "Rounding" and the
     * journal would have balanced, which is the worst way for a bug to hide:
     * the trial balance says nothing and the money sits in an expense head
     * nobody reads. Anything above the ceiling is a defect and is REFUSED with
     * a sentence (JournalException, which the app shows as a form error, never
     * a 500).
     *
     * ⚠⚠ WHY THE CEILING IS NOT A FLAT 5 PAISA. The honest residue comes from
     * a unit cost carried at 4 decimals: up to Tk 0.00005 per UNIT, so it grows
     * with the quantity, not with a mistake. Two real cases already in the
     * suite: 3,000 units out of a Tk 100,000 production run leave 10 paisa, and
     * 1,000 kg of oil received in litres leaves 12. A flat 5 paisa would refuse
     * the business's own mustard oil receipts. So the ceiling is the larger of
     * 5 paisa and 1 paisa per Tk 100 of the journal's debits (0.01%): a
     * Tk 1 lakh journal may carry up to Tk 10 of rounding, and a dropped
     * Tk 50,000 line is refused on any journal below Tk 5 crore.
     */
    public const MAX_MINOR = 5;

    /** 1 paisa of tolerance per this many paisa debited (Tk 100). */
    public const MINOR_PER_TOLERATED_PAISA = 10_000;

    /**
     * @param  list<JournalLineDraft>  $lines
     * @param  Money|null  $scale  the document's gross value when its journal is
     *                             NETTED (a production run posts only the net
     *                             stock change, so its lines are tiny while the
     *                             residue is sized by the whole run).
     */
    public static function ceilingFor(array $lines, ?Money $scale = null): int
    {
        $debits = 0;
        foreach ($lines as $line) {
            if ($line->amount->minor > 0) {
                $debits += $line->amount->minor;
            }
        }
        $debits = max($debits, $scale?->absolute()->minor ?? 0);

        return max(self::MAX_MINOR, intdiv($debits, self::MINOR_PER_TOLERATED_PAISA));
    }

    /**
     * Appends a balancing line if, and only if, one is needed.
     *
     * @param  list<JournalLineDraft>  $lines
     * @return list<JournalLineDraft>
     */
    public static function balance(array $lines, Carbon $on, string $memo = 'Rounding', ?Money $scale = null): array
    {
        $difference = Money::sum(array_map(fn (JournalLineDraft $line) => $line->amount, $lines));

        if ($difference->isZero()) {
            return $lines;
        }

        if ($difference->absolute()->minor > self::ceilingFor($lines, $scale)) {
            throw JournalException::roundingTooLarge($difference, $memo);
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
