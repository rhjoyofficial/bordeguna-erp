<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Carbon;

/**
 * Books what a till was over or short by when its shift was counted.
 *
 * The till's ledger balance is what the receipts and payments say should be in
 * the drawer; the count is what is. Until this existed the two simply drifted
 * apart, one shift at a time, and the cash on the balance sheet stopped being
 * money anybody could find.
 *
 *   short:  Dr Cash Over and Short / Cr the till
 *   over:   Dr the till            / Cr Cash Over and Short
 *
 * ⚠ Returns null, and posts nothing, when the books are not set up for this
 * business (no mapping, or a till not tied to a ledger account). Closing a
 * shift is a physical act at the end of a working day and must never fail
 * because of accounting configuration.
 */
class PostTillOverShort
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(MoneyAccount $till, Money $overShort, int $shiftId, Carbon $on, User $by): ?Journal
    {
        if ($overShort->isZero() || $till->account_id === null) {
            return null;
        }

        $mapping = AccountMapping::forKeyOn('sales.cash_over_short', $on);

        if ($mapping === null) {
            return null;
        }

        $memo = sprintf('Till %s %s, shift #%d', $overShort->minor > 0 ? 'over' : 'short', $till->name, $shiftId);

        // Signed: a positive over/short is cash FOUND, so the till is debited.
        return $this->postJournal->handle(new JournalDraft(
            date: $on->copy()->startOfDay(),
            lines: [
                JournalLineDraft::signed($till->account_id, $overShort, $memo, null, $till),
                JournalLineDraft::signed($mapping->account_id, $overShort->negated(), $memo),
            ],
            memo: $memo,
        ), $by);
    }
}
