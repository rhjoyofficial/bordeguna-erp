<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\Voucher;

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
    /** @var array<int, string|null> journal id => voucher counterparty, per request */
    private static array $counterparties = [];

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
            // ⭐ No linked customer, supplier or employee (12 Oct 2026, the BRAC
            // entries before those records existed): show the voucher's own
            // Paid to / Received from instead of an empty cell.
            return self::voucherCounterparty((int) $this->line->journal_id);
        }

        return PartyLedgers::label($this->line->party, $this->line->party_type, (int) $this->line->party_id);
    }

    private static function voucherCounterparty(int $journalId): ?string
    {
        if (! array_key_exists($journalId, self::$counterparties)) {
            $name = Voucher::query()->where('journal_id', $journalId)->orWhere('reversal_journal_id', $journalId)->value('counterparty');
            self::$counterparties[$journalId] = is_string($name) && trim($name) !== '' ? $name : null;
        }

        return self::$counterparties[$journalId];
    }
}
