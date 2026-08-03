<?php

namespace App\Modules\Accounting\Enums;

/**
 * What KIND of entry a journal is.
 *
 * ⚠⚠ THE TYPE MUST NEVER CHANGE THE MECHANISM, and a structural test enforces
 * it: there is no `match` on this enum anywhere in PostJournal or ReverseJournal.
 *
 * The type says *why the entry exists*; the date, the accounts and the amounts
 * say *what happens*. This is the same rule that governs
 * InventoryAdjustmentType, where it has now survived a genuinely new seventh
 * case being added without a single change to the engine that consumes it - the
 * one real test of a claim that is otherwise only made in principle.
 *
 * ⚠ What the type is FOR is telling opening balances apart from trading. The
 * opening entries are a photograph of the business on the day the books start;
 * everything after them is the business moving. A report that cannot separate
 * the two shows a year of trading that begins with a phantom sale.
 */
enum JournalType: string
{
    case Manual = 'manual';

    /**
     * ⚠ Dated the day before the accounting go-live date and posted against
     * 3900 Opening Balance Equity, which must read ZERO once every opening
     * figure is in. A trial balance can balance perfectly and still be wrong;
     * that account going to zero is the only check that catches it.
     */
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return [
            'manual' => 'Manual journal',
            'opening_balance' => 'Opening balance',
        ][$this->value] ?? $this->value;
    }
}
