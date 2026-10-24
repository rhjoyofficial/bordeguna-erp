<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a posted owner withdrawal
 * taken back, as if the proprietor had never taken the goods (the engine's
 * Reverse, OwnerWithdrawalCorrector).
 *
 *   stock    every line's goods back ONTO the batch they left, at the cost
 *            they left at (RestoreStockIssue on the line's own movement,
 *            reason owner_withdrawal, `reverses_movement_id` naming it)
 *   journal  the drawings journal mirrored (ReverseJournal):
 *            Dr Stock on Hand / Cr Owner's Drawings, the same lines negated
 *
 * ⚠⚠ Refused while a batch's cost has changed since (a landed cost, a
 * discount, a revaluation): RestoreStockIssue puts goods back at the batch's
 * CURRENT cost, while the mirror journal gives Stock on Hand back the cost
 * they left at, so the stock and the ledger would part by the difference.
 *
 * The caller (the engine) holds the transaction and the locks.
 */
class ReverseOwnerWithdrawal
{
    public function __construct(
        private readonly RestoreStockIssue $restore,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    /** The first sentence that stops a reversal, or null. */
    public static function blocker(OwnerWithdrawal $withdrawal): ?string
    {
        if ($withdrawal->isReversed()) {
            return "Owner personal withdrawal {$withdrawal->number} was already reversed.";
        }

        if (! $withdrawal->isPosted()) {
            return "Owner personal withdrawal {$withdrawal->number} is not posted, so there is nothing to reverse.";
        }

        foreach ($withdrawal->lines()->with(['product:id,name', 'movement'])->get() as $line) {
            /** @var OwnerWithdrawalLine $line */
            $name = $line->product?->name ?? "product #{$line->product_id}";
            $movement = $line->movement;
            $batch = $movement === null ? null : StockBatch::query()->find($movement->batch_id);

            if ($movement === null || $batch === null) {
                return "{$name} on {$withdrawal->number} has no stock movement. Ask the administrator to check it.";
            }

            if (StockMovement::query()->where('reverses_movement_id', $movement->id)->exists()) {
                return "{$name} on {$withdrawal->number} was already put back. Ask the administrator to check it.";
            }

            if (bccomp((string) $batch->unit_cost, (string) $movement->unit_cost, 4) !== 0) {
                return "Batch {$batch->batch_number} of {$name} was re-costed since, so the goods cannot go back at the cost they left at.";
            }
        }

        return null;
    }

    public function handle(OwnerWithdrawal $withdrawal, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null): ?Journal
    {
        if (($blocked = self::blocker($withdrawal)) !== null) {
            throw new DomainException($blocked);
        }

        $note = "Owner personal withdrawal {$withdrawal->number} reversed: {$reason}";
        $movements = $withdrawal->lines()->with('movement')->get()->map(fn (OwnerWithdrawalLine $line) => $line->movement);

        $this->restore->handle(
            restorations: $movements->map(fn (StockMovement $out) => ['movement' => $out, 'quantity' => (string) $out->quantity])->all(),
            performedBy: $by,
            note: $note,
            reason: MovementReason::OwnerWithdrawal,
            occurredAt: $this->when($movements->first(), $on),
            pass: $pass,
        );

        $journal = $withdrawal->journal_id === null ? null : Journal::query()->find($withdrawal->journal_id);
        $reversal = $journal === null ? null : $this->reverseJournal->handle($journal, $by, $on, mb_substr($note, 0, 250), $pass);

        $withdrawal->forceFill([
            'reversed_on' => $on->toDateString(),
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();

        return $reversal;
    }

    /**
     * On the withdrawal's own day the goods come back at the moment they left
     * (a bare date would be midnight, before they left); on a later day, at
     * its end.
     */
    private function when(StockMovement $movement, Carbon $on): Carbon
    {
        $original = Carbon::parse($movement->occurred_at);

        return $original->isSameDay($on) ? $original : $on->copy()->setTime(23, 59, 59);
    }
}
