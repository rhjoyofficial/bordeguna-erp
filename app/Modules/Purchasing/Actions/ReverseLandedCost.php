<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\PostStockCostDifference;
use App\Modules\Inventory\Actions\RevalueStockBatch;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\SoldStock;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\LandedCostAllocation;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a POSTED landed cost taken
 * back (the engine's Reverse, LandedCostCorrector). The mirror of
 * PostLandedCost, in four parts:
 *
 *   1. stock    on every batch it revalued, the cost it added to the goods
 *               STILL ON HAND comes off again (RevalueStockBatch, value only,
 *               the batch back to the unit cost it had before), on the
 *               reversal date;
 *   2. sold     the cost it added to goods sold or used SINCE stays on those
 *               sales (past sales are never re-costed); the difference it
 *               leaves between the ledger and the stock posts TODAY to Stock
 *               Cost Corrections on Goods Sold (PostStockCostDifference, the
 *               Tk 10,000 rule: above it only the Owner);
 *   3. journal  its journal mirrored (ReverseJournal): Stock on Hand, the
 *               consumed-stock head and the expense heads come down, Accounts
 *               Payable or the money account goes back up;
 *   4. bills    the supplier bills it raised are marked reversed with it (they
 *               carry no journal of their own: the landed cost's journal was
 *               the payable). Refused while any of them was paid, credited,
 *               discounted or had an advance applied: that is reversed first.
 *
 * ⚠⚠ The share left on a batch is worked out from the movements, never from
 * the allocation's planned figures: the cost it added (its own value-only
 * movements) less what left the batch since at the higher cost
 * (Σ quantity x out cost - quantity x the cost before). The batch then holds
 * exactly the value it would hold had the landed cost never been posted, and
 * the ledger and the stock still agree to the paisa once part 2 is posted.
 *
 * Refused in a sentence where that sum would not be the whole story: another
 * value change on the batch since (another landed cost, a discount, a
 * revaluation), goods that came back into it, goods moved to another
 * warehouse or sent back to the supplier since (those units still carry the
 * cost elsewhere), and a landed cost posted before bills were raised (its
 * payable is counted by line, not by a bill that can be marked).
 *
 * The caller (the engine) holds the transaction and the locks.
 */
class ReverseLandedCost
{
    public function __construct(
        private readonly RevalueStockBatch $revalue,
        private readonly ReverseJournal $reverseJournal,
        private readonly PostStockCostDifference $costDifference,
    ) {}

    /**
     * Per batch: the cost the landed cost added that is still on hand, and
     * the part that left on goods sold or used since.
     *
     * @return array{batches: list<array{batch: StockBatch, old_cost: string, first: StockMovement, remaining: int, sold: int, used: string}>, sold: int, used: string, refusal: ?string}
     */
    public static function plan(LandedCost $landedCost): array
    {
        $batches = [];
        $soldTotal = 0;
        $usedTotal = 0;

        $groups = LandedCostAllocation::query()
            ->whereIn('landed_cost_line_id', $landedCost->lines()->select('id'))
            ->whereNotNull('stock_movement_id')
            ->get()
            ->groupBy('stock_batch_id');

        foreach ($groups as $batchId => $allocations) {
            $batch = StockBatch::query()->find($batchId);
            $own = StockMovement::query()->whereIn('id', $allocations->pluck('stock_movement_id'))->orderBy('id')->get();

            if ($batch === null || $own->isEmpty()) {
                return ['batches' => [], 'sold' => 0, 'used' => '0', 'refusal' => "Landed cost {$landedCost->number} has lost a stock movement. Ask the administrator to check it."];
            }

            $name = $batch->batch_number ?? "#{$batch->id}";
            $first = $own->first();
            $after = StockMovement::query()->where('batch_id', $batch->id)->where('id', '>', $first->id)->whereNotIn('id', $own->pluck('id'))->orderBy('id')->get();

            $refusal = match (true) {
                $after->contains(fn (StockMovement $m) => SoldStock::scaled((string) $m->quantity) === 0) => "Batch {$name}'s cost was changed again since by another landed cost, a discount or a revaluation, so reverse that first.",
                $after->contains(fn (StockMovement $m) => $m->direction === MovementDirection::In) => "Goods came back into batch {$name} since, so this landed cost cannot be taken off it here.",
                $after->contains(fn (StockMovement $m) => $m->reason === MovementReason::Transfer) => "Goods from batch {$name} were moved to another warehouse since, so they carry this cost there. Move them back first.",
                $after->contains(fn (StockMovement $m) => $m->reason === MovementReason::PurchaseReturn) => "Goods from batch {$name} went back to the supplier since, so reverse that return first.",
                default => null,
            };

            if ($refusal !== null) {
                return ['batches' => [], 'sold' => 0, 'used' => '0', 'refusal' => $refusal];
            }

            // The batch's cost before the landed cost: every movement carries
            // the batch cost of its moment (a receipt its own, an issue the
            // cost it left at, a revaluation the cost it set).
            $before = StockMovement::query()->where('batch_id', $batch->id)->where('id', '<', $first->id)->orderByDesc('id')->first();
            $oldCost = (string) ($before?->unit_cost ?? $batch->unit_cost);

            $added = $own->sum(fn (StockMovement $m) => ($m->direction === MovementDirection::In ? 1 : -1) * Money::ofTaka((string) $m->value_delta)->absolute()->minor);
            $sold = 0;
            $used = 0;

            foreach ($after as $out) {
                $sold += StockValue::of($out)->absolute()->minor - Money::ofProduct((string) $out->quantity, $oldCost)->minor;
                $used += SoldStock::scaled((string) $out->quantity);
            }

            $batches[] = ['batch' => $batch, 'old_cost' => $oldCost, 'first' => $first, 'remaining' => $added - $sold, 'sold' => $sold, 'used' => SoldStock::unscaled($used)];
            $soldTotal += $sold;
            $usedTotal += $used;
        }

        return ['batches' => $batches, 'sold' => $soldTotal, 'used' => SoldStock::unscaled($usedTotal), 'refusal' => null];
    }

    /** The first sentence that stops a reversal (the Tk 10,000 rule aside), or null. */
    public static function blocker(LandedCost $landedCost): ?string
    {
        $number = $landedCost->number;

        if ($landedCost->isReversed()) {
            return "Landed cost {$number} was already reversed.";
        }

        if (! $landedCost->isPosted()) {
            return "Landed cost {$number} is not posted, so there is nothing to reverse.";
        }

        if ($landedCost->journal_id === null) {
            return "Landed cost {$number} has no journal. Ask the administrator to check it.";
        }

        $owesSupplier = $landedCost->lines()->whereNotNull('supplier_id')->exists();
        $bills = $landedCost->bills()->get();

        if ($owesSupplier && $bills->isEmpty()) {
            return "Landed cost {$number} was posted before supplier bills were raised, so it cannot be reversed here. Record a supplier credit note instead.";
        }

        foreach ($bills as $bill) {
            if (($sentence = self::billRefusal($bill, $number)) !== null) {
                return $sentence;
            }
        }

        return self::plan($landedCost)['refusal'];
    }

    public function handle(LandedCost $landedCost, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null, ?User $approver = null): ?Journal
    {
        if (($blocked = self::blocker($landedCost)) !== null) {
            throw new DomainException($blocked);
        }

        $plan = self::plan($landedCost);

        if (($owner = SoldStock::ownerBlocker($plan['sold'], $by, $approver)) !== null) {
            throw new DomainException($owner->message);
        }

        AccountingPeriod::assertOpenFor($on, $pass);

        $note = "Landed cost {$landedCost->number} reversed: {$reason}";

        // 1. The share still on hand comes off each batch, back to its old cost.
        foreach ($plan['batches'] as $entry) {
            if ($entry['remaining'] === 0 && SoldStock::scaled((string) $entry['batch']->current_quantity) === 0) {
                continue;
            }

            $this->revalue->handle(
                batch: $entry['batch'],
                direction: $entry['remaining'] >= 0 ? MovementDirection::Out : MovementDirection::In,
                newUnitCost: $entry['old_cost'],
                valueDelta: Money::ofMinor(abs($entry['remaining']))->toTaka(),
                performedBy: $by->id,
                note: mb_substr($note, 0, 250),
                occurredAt: $this->when($entry['first'], $on),
                reason: MovementReason::LandedCost,
            );
        }

        // 3. The journal mirrored, on the reversal date.
        $journal = Journal::query()->findOrFail($landedCost->journal_id);
        $reversal = $this->reverseJournal->handle($journal, $by, $on, mb_substr($note, 0, 250), $pass);

        // 2. The part that left on goods sold since: today, to its own head.
        if ($plan['sold'] !== 0) {
            $this->costDifference->handle($landedCost, (string) $landedCost->number, $plan['sold'], $plan['used'], $note, $by, $approver);
        }

        // 4. The bills it raised go with it.
        foreach ($landedCost->bills()->whereNull('reversed_at')->get() as $bill) {
            $bill->forceFill([
                'reversed_at' => $on,
                'reversed_by' => $by->id,
                'reversal_note' => mb_substr($note, 0, 500),
            ])->save();
        }

        $landedCost->forceFill([
            'reversed_on' => $on->toDateString(),
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal->id,
        ])->save();

        return $reversal;
    }

    private static function billRefusal(SupplierBill $bill, string $number): ?string
    {
        if ($bill->isReversed()) {
            return null;
        }

        $what = match (true) {
            $bill->paidTotal()->minor > 0 => 'has been paid',
            $bill->creditedTotal()->minor > 0 => 'has been credited',
            $bill->adjustedTotal()->minor > 0 => 'has a discount or set-off against it',
            SupplierAdvanceApplication::query()->where('supplier_bill_id', $bill->id)->whereNull('reversed_at')->exists() => 'has an advance applied to it',
            SupplierDiscount::query()->where('supplier_bill_id', $bill->id)->whereNull('reversed_at')->exists() => 'carries a supplier discount',
            default => null,
        };

        return $what === null ? null : "Bill {$bill->bill_number} raised by landed cost {$number} {$what}. Reverse that first, then reverse the landed cost.";
    }

    /**
     * On the landed cost's own day the value comes off at the moment it went
     * on; on a later day, at its start (value only: no quantity can go below
     * zero whatever the hour).
     */
    private function when(StockMovement $movement, Carbon $on): Carbon
    {
        $original = Carbon::parse($movement->occurred_at);

        return $original->isSameDay($on) ? $original : $on->copy()->startOfDay();
    }
}
