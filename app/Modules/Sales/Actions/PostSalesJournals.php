<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Support\Carbon;

/**
 * The sales events that move value into the books.
 *
 * **Goods leaving:** Dr Goods Delivered Not Invoiced   Cr Stock on Hand
 *
 * ⚠⚠ GDNI IS THE MIRROR OF GRNI, AND IT IS WHAT MAKES THE MONTH CLOSE. Between
 * the goods leaving on a challan and the invoice being raised, the business no
 * longer holds the stock but has not yet earned anything against it. Without
 * this account the cost lands in the month the lorry left and the revenue lands
 * in the month the invoice went out, and every gross margin is drawn from two
 * different periods - a fake loss one month, a fake profit the next.
 *
 * ⚠ Joy's distributor flow is "challan today, invoice at month end", so that
 * split is the NORMAL case here, not an edge case.
 *
 * ⚠ A GDNI balance is a real report - "out on challan, not yet billed" - rather
 * than a suspense account. For a counter sale it is debited and credited inside
 * the same transaction and nets to zero instantly.
 *
 * ⚠ Lives in Sales. The ledger never learns what a delivery is.
 */
class PostSalesJournals
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * ⚠⚠ Valued from the MOVEMENTS the delivery actually created, never from a
     * selling price and never from an average cost. One line at FIFO consumes
     * several batches at several costs, and the sum of those layers IS the cost
     * of goods sold. Anything else is a second opinion about what the stock
     * cost - the same rule that values a goods receipt from its movement rather
     * than from the purchase order.
     */
    public function forDelivery(Delivery $delivery, User $postedBy): ?Journal
    {
        if ($this->existingJournal($delivery) !== null) {
            return null;
        }

        $on = Carbon::parse($delivery->delivered_at)->startOfDay();

        // ⚠⚠ THE ANCHOR. A business whose ledger is not configured must still be
        // able to send goods to a customer. Absent means Sales posts nothing and
        // behaves exactly as it did before Accounting existed.
        if (AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return null;
        }

        // ⚠ Resolved, not checked: if sales posting is switched on but one of
        // its accounts is unmapped, that is a defect and the event must fail
        // loudly rather than post a half-entry.
        $gdni = AccountMapping::resolve('sales.goods_delivered_not_invoiced', $on);
        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);

        $lines = [];
        $total = Money::zero();

        $delivery->loadMissing('lines.movements');

        foreach ($delivery->lines as $line) {
            foreach ($line->movements as $movement) {
                $value = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

                if ($value->isZero()) {
                    continue;
                }

                $lines[] = JournalLineDraft::credit($stock->id, $value);
                $total = $total->plus($value);
            }
        }

        // ⚠ Zero-value stock is real - a free sample from a batch received at no
        // cost - and it is not an error. There is simply nothing to post.
        if ($lines === [] || $total->isZero()) {
            return null;
        }

        $lines[] = JournalLineDraft::debit(
            $gdni->id,
            $total,
            'Delivered not invoiced',
            $delivery->customer,
        );

        $lines = LedgerRounding::balance($lines, $on, "Rounding on delivery {$delivery->delivery_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Delivery {$delivery->delivery_number}",
            source: $delivery,
        ), $postedBy);
    }

    public function existingJournal(Delivery $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
