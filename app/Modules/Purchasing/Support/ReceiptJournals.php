<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Purchasing\Models\GoodsReceipt;

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): the ONE live journal a goods receipt
 * stands on: its source is the receipt, it is not itself a reversal, and
 * nothing has reversed it.
 *
 * ⚠ Not PostPurchaseJournals::existingJournal(). That one answers "was a
 * journal EVER posted for this receipt" (so a receipt is never posted twice)
 * and returns the first one even when it was reversed since. After a
 * supplier correction re-posted the receipt, the live journal is the second.
 */
final class ReceiptJournals
{
    public static function live(GoodsReceipt $receipt): ?Journal
    {
        return Journal::query()
            ->where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->orderByDesc('id')
            ->first();
    }

    /** How many live journals the receipt has (1 expected; 0 when it moved no value). */
    public static function liveCount(GoodsReceipt $receipt): int
    {
        return Journal::query()
            ->where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->count();
    }
}
