<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Support\Carbon;

/**
 * Turns an approved inventory correction into its accounting entry.
 *
 * A decrease:  Dr the type's loss account   Cr Stock on Hand
 * An increase: Dr Stock on Hand             Cr the type's loss account
 *
 * ⚠⚠ NO `match` ON THE ADJUSTMENT TYPE. The type chooses a MAPPING KEY, which
 * is a lookup, not a branch - so a damaged write-off, an expiry and a
 * revaluation take literally the same path here, exactly as they do through the
 * adjustment engine itself. An eighth type needs a mapping row and no code.
 *
 * ⚠ Lives in Inventory, not Accounting: the ledger never learns what a stock
 * batch is. Same rule that put PostPayrollAccrual in HRM.
 *
 * ⚠ Transfers post NOTHING and never reach here. Stock moving between two
 * warehouses of one business changes nothing the ledger can see, and a zero-sum
 * pair of lines would be noise in every report that opened the account.
 */
class PostInventoryAdjustmentJournal
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(InventoryAdjustment $adjustment, User $postedBy): ?Journal
    {
        if ($this->existingJournal($adjustment) !== null) {
            return null;
        }

        $on = Carbon::parse($adjustment->effective_date)->startOfDay();

        // ⚠ The anchor. A business that has not configured its ledger keeps
        // adjusting stock exactly as before and posts nothing - an accounting
        // feature must never be able to stop an operational process. The
        // payroll integration learned this the expensive way.
        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
        $counter = AccountMapping::resolve(
            "inventory.adjustment.{$adjustment->type->value}",
            $on,
            'inventory.adjustment.default',
        );

        $lines = [];
        $total = Money::zero();

        // ⚠⚠ ONE LINE PER COST LAYER, each rounded ONCE, and the contra derived
        // from the SUM of those rounded figures. Both sides are then made of the
        // same numbers, so the journal balances by construction rather than by
        // luck - which is why the rounding residual below is usually idle.
        foreach ($adjustment->movements as $movement) {
            $value = $this->valueOf($movement);

            if ($value->isZero()) {
                continue;
            }

            $lines[] = JournalLineDraft::signed($stock->id, $value, $movement->note);
            $total = $total->plus($value);
        }

        if ($lines === [] || $total->isZero()) {
            return null;
        }

        // The counter side: whatever the stock side did, in reverse.
        $lines[] = JournalLineDraft::signed($counter->id, $total->negated(), $adjustment->type->label());

        $lines = LedgerRounding::balance($lines, $on, "Rounding on adjustment #{$adjustment->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Stock adjustment #{$adjustment->id} - {$adjustment->type->label()}",
            source: $adjustment,
        ), $postedBy);
    }

    /**
     * What a movement is worth to the ledger, signed the way stock moved.
     *
     * ⚠⚠ THE RULE NOW LIVES IN ONE PLACE, `StockValue`. It used to live here and
     * ALSO, differently, in `SubledgerReconciliation` - this engine knew that
     * `value_delta` wins when set, and the reconciliation did not, so every
     * revaluation posted to the ledger and was valued at nothing by the figure
     * that checks it.
     *
     * ⚠⚠ AND THE SIGN CHANGED. This method used to return `value_delta`
     * unsigned. It is stored as a positive MAGNITUDE with its direction on the
     * movement, so a write-DOWN returned a positive amount, and a positive
     * amount is a debit: writing stock down INCREASED inventory and credited the
     * write-off expense. `StockValue::of()` applies the movement's own direction
     * to both kinds of movement, so a write-down now credits inventory.
     */
    private function valueOf(StockMovement $movement): Money
    {
        return StockValue::of($movement);
    }

    public function existingJournal(InventoryAdjustment $adjustment): ?Journal
    {
        return Journal::query()
            ->where('source_type', $adjustment->getMorphClass())
            ->where('source_id', $adjustment->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
