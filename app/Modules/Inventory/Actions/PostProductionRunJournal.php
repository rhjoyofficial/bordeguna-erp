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
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Support\Carbon;

/**
 * Turns a confirmed production run into its accounting entry - which is
 * usually NO entry, and that is correct (C-MFG-5).
 *
 * A fully absorbed run credits and debits Stock on Hand the same amount: the
 * value changed shape, not amount, so there is nothing for the ledger to say -
 * the same reasoning that makes transfers post nothing. A zero-sum pair of
 * lines would be noise in every report that opened the account.
 *
 * What CAN post:
 *   Expensed waste     Dr Production wastage loss / Cr Stock on Hand, through
 *                      the MAPPING KEY `inventory.production_wastage`, never a
 *                      hard-coded account.
 *   Rounding residue   The output batch's unit cost lives at four decimal
 *                      places, so a large run can be a paisa or two away from
 *                      carrying the consumed value exactly. That lands on
 *                      Rounding Differences, visibly, rather than silently
 *                      breaking the movement-valued subledger against the
 *                      ledger (Joy's 2026-08-03 rounding instruction).
 *
 * ⚠ The anchor rule, twice: no `inventory.stock_on_hand` mapping - or an
 * expensed-waste run with no `inventory.production_wastage` mapping - means the
 * run still works and posts NOTHING. An accounting feature must never stop an
 * operational process (the payroll integration's lesson).
 */
class PostProductionRunJournal
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(ProductionRun $run, User $postedBy): ?Journal
    {
        if ($this->existingJournal($run) !== null) {
            return null;
        }

        $on = Carbon::parse($run->occurred_at)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        // ⚠ Valued from the MOVEMENTS, per movement, rounded once each - the
        // StockValue rule. This is the same figure the subledger reconciliation
        // computes, which is exactly why the ledger must move by it.
        $net = StockValue::sum($run->movements);

        $waste = $run->expense_waste && $run->waste_value !== null
            ? Money::ofTaka((string) $run->waste_value)
            : Money::zero();

        if (! $waste->isZero() && AccountMapping::forKeyOn('inventory.production_wastage', $on) === null) {
            return null;
        }

        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);

        $lines = [];

        if (! $net->isZero()) {
            $lines[] = JournalLineDraft::signed($stock->id, $net, "Production run {$run->run_number}");
        }

        if (! $waste->isZero()) {
            $wastage = AccountMapping::resolve('inventory.production_wastage', $on);
            $lines[] = JournalLineDraft::debit($wastage->id, $waste, 'Production wastage');
        }

        // The absorbed, residue-free run: nothing to say, correctly.
        if ($lines === []) {
            return null;
        }

        // ⚠ A residue with no rounding mapping configured falls under the anchor
        // rule too: LedgerRounding would throw, and an accounting gap must not
        // stop the run. Skipping the post leaves the movements standing.
        $imbalance = Money::sum(array_map(fn (JournalLineDraft $line) => $line->amount, $lines));

        if (! $imbalance->isZero() && AccountMapping::forKeyOn('accounting.rounding_difference', $on) === null) {
            return null;
        }

        // 14 Oct 2026 (audit #8): the journal is NETTED, so its own lines are
        // as small as the residue; the rounding ceiling is sized by what the
        // run consumed instead.
        $lines = LedgerRounding::balance($lines, $on, "Rounding on production run {$run->run_number}", Money::ofTaka((string) ($run->consumed_value ?? '0')));

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Production run {$run->run_number} - {$run->outputProduct->name}",
            source: $run,
        ), $postedBy);
    }

    public function existingJournal(ProductionRun $run): ?Journal
    {
        return Journal::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
