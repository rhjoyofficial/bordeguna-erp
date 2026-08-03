<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\JournalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a caller hands to PostJournal: a date, a reason, and a set of lines.
 *
 * ⚠⚠ THE INTERFACE IS SOURCE-AGNOSTIC AND MUST STAY THAT WAY. Inventory,
 * Purchasing, Payroll, Sales and the manual journal screen all hand over exactly
 * this. The ledger knows nothing about stock or salaries, which is what lets
 * Sales be added in a year without touching it (review principle 5.1). If a
 * future subsystem needs this class changed to accept its events, 5.1 was wrong
 * and that is worth knowing loudly rather than working around quietly.
 *
 * ⚠ It carries ACCOUNT IDS, never account codes and never a subsystem concept
 * like "stock write-off". Turning an event into accounts is the mapping layer's
 * job in Phase 3, and keeping it out of here is what stops the ledger from
 * slowly learning what a purchase order is.
 */
final class JournalDraft
{
    /**
     * @param  list<JournalLineDraft>  $lines
     */
    public function __construct(
        public readonly Carbon $date,
        public readonly array $lines,
        public readonly ?string $memo = null,
        public readonly ?Model $source = null,
        public readonly JournalType $type = JournalType::Manual,
    ) {}

    public function total(): Money
    {
        return Money::sum(array_map(fn (JournalLineDraft $line) => $line->amount, $this->lines));
    }
}
