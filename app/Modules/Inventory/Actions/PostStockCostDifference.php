<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Models\StockCostDifference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * ⭐ Posts the cost difference a correction left on goods already sold or used,
 * TODAY, through the posting engine (corrections wave 1 gaps, 24 Oct 2026;
 * the Tk 10,000 rule, SoldStock).
 *
 *   difference > 0  Dr Stock on Hand / Cr Stock Cost Corrections on Goods Sold
 *                   (the ledger took more off stock than the batches gave back:
 *                   cost of sales comes down)
 *   difference < 0  Dr Stock Cost Corrections on Goods Sold / Cr Stock on Hand
 *
 * The caller works out the difference from what it really wrote (the
 * movements and the journals), so Stock on Hand and the stock movements still
 * agree to the paisa afterwards.
 *
 * ⚠ TODAY, never the original's date (FINAL-PLAN 4.3: "Past sales are never
 * re-costed"). Today is an open day, so no closed-month pass is ever needed
 * or handed in here.
 *
 * ⚠ The anchor rule: no `inventory.stock_on_hand` mapping today means the
 * ledger does not track stock, so the row is kept with no journal. A mapped
 * stock head without the cost-correction head is refused: the correction
 * would leave the ledger and the stock apart.
 */
class PostStockCostDifference
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(Model $source, string $sourceNumber, int $differenceMinor, string $quantityUsed, string $note, User $by, ?User $approver = null): ?StockCostDifference
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A stock cost difference is posted only inside its correction.');
        }

        if ($differenceMinor === 0) {
            return null;
        }

        $today = Carbon::today();
        $stock = AccountMapping::forKeyOn('inventory.stock_on_hand', $today)?->account;
        $head = AccountMapping::forKeyOn('inventory.cost_correction_consumed', $today)?->account;

        if ($stock !== null && $head === null) {
            throw ValidationException::withMessages(['reason_text' => 'The head for stock cost corrections on goods sold is not set up yet. Ask the administrator.']);
        }

        $amount = Money::ofMinor($differenceMinor);

        $row = StockCostDifference::create([
            'business_id' => $source->getAttribute('business_id'),
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'source_number' => mb_substr($sourceNumber, 0, 60),
            'posted_on' => $today->toDateString(),
            'quantity_used' => $quantityUsed,
            'amount' => $amount->toTaka(),
            'note' => mb_substr($note, 0, 500),
            'created_by' => $by->id,
            'approved_by' => $approver?->id,
        ]);

        if ($stock === null) {
            return $row;
        }

        $memo = mb_substr("Stock cost correction on goods already sold: {$sourceNumber}", 0, 250);
        $magnitude = $amount->absolute();

        $lines = $differenceMinor > 0
            ? [JournalLineDraft::debit($stock->id, $magnitude, $memo), JournalLineDraft::credit($head->id, $magnitude, $memo)]
            : [JournalLineDraft::debit($head->id, $magnitude, $memo), JournalLineDraft::credit($stock->id, $magnitude, $memo)];

        $journal = $this->postJournal->handle(new JournalDraft(
            date: $today,
            lines: $lines,
            memo: $memo,
            source: $row,
        ), $by);

        $row->forceFill(['journal_id' => $journal->id])->save();

        return $row;
    }
}
