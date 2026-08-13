<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\JournalLine;

/**
 * One line of a general ledger, with the running balance it produced.
 *
 * ⚠ The running balance is passed IN, not computed here. It depends on every
 * line before this one in the report's order, which is knowledge that belongs to
 * `AccountLedger` and nowhere else - a row that computed its own would have to
 * know the ordering rule too, and then there would be two copies of it.
 */
final class LedgerEntry
{
    public function __construct(
        public readonly JournalLine $line,
        public readonly Money $balance,
    ) {}

    public function amount(): Money
    {
        return $this->line->amount();
    }

    public function isDebit(): bool
    {
        return $this->line->isDebit();
    }

    /**
     * The supplier owed, the customer who owes, the employee paid.
     *
     * ⚠ Falls back to the type and id rather than to an empty cell. A party row
     * whose record was later deactivated must still be identifiable, and a blank
     * where a name should be reads as "no party", which is a different fact.
     */
    public function partyName(): ?string
    {
        if ($this->line->party_type === null) {
            return null;
        }

        $party = $this->line->party;

        if ($party === null) {
            return class_basename($this->line->party_type)." #{$this->line->party_id}";
        }

        return $party->name ?? class_basename($party)." #{$party->getKey()}";
    }
}
