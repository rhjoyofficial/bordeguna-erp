<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts an AUTHORISED owner withdrawal: the goods leave and the ledger follows
 * (Phase 2, 17 Oct 2026, ruling Q2 "Proprietor Moin", decisions D1 and D5).
 *
 *   stock out   one movement per line, on the RESERVED batch, reason
 *               owner_withdrawal, at that batch's unit cost
 *   journal     Dr  inventory.owner_withdrawal (3200 Owner's Drawings)  total at cost
 *                   Cr  inventory.stock_on_hand (1150)                 per line
 *
 * NO revenue, NO customer, NO receivable: this is a drawing. The reference
 * price stays on the line as evidence and reaches no account.
 *
 * ⚠⚠ The batch is checked AGAIN here, under lock: it must still be the one
 * authorised, consumable, hold the quantity, and carry the frozen cost. When
 * it does not (sold meanwhile with the hold released by hand, re-costed by a
 * landed cost, put on hold, expired), the document is NOT re-priced quietly:
 * it goes back to draft with the reason and the posting stops (ruling Q1).
 *
 * ⚠ Cr Stock on Hand per line is `quantity x unit_cost` through Money, which is
 * exactly StockValue::of() the movement, so the stock reconciliation ties by
 * construction (same rule as the counter sale's COGS).
 */
class PostOwnerWithdrawal
{
    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly ReleaseReservation $release,
        private readonly ReopenOwnerWithdrawal $reopen,
    ) {}

    public function handle(OwnerWithdrawal $withdrawal, User $postedBy): OwnerWithdrawal
    {
        if (! $withdrawal->isApproved()) {
            throw new RuntimeException("Owner withdrawal {$withdrawal->number} is ".strtolower($withdrawal->status->label()).', so it cannot be posted.');
        }

        $on = Carbon::parse($withdrawal->withdrawn_on)->startOfDay();

        // ⚠⚠ Re-checked HERE, not only at authorisation: a month can close
        // while the document waits.
        AccountingPeriod::assertOpenFor($on);

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            throw new RuntimeException('Inventory posting is not switched on for this business (inventory.stock_on_hand is unmapped), so an owner withdrawal has nowhere to post.');
        }

        $drawings = AccountMapping::resolve('inventory.owner_withdrawal', $on);
        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);

        // A first pass without locks: when the reserved batch no longer fits,
        // send the document back (its own transaction) and stop with the reason.
        $withdrawal->load('lines.batch');

        if (($reason = $this->refusal($withdrawal, $on)) !== null) {
            $this->reopen->handle($withdrawal, $reason);

            throw new RuntimeException("Owner withdrawal {$withdrawal->number} was sent back for re-approval: {$reason}");
        }

        $movedAt = EffectiveDate::resolveConsumingDay($on->toDateString(), 'withdrawn_on');

        return DB::transaction(function () use ($withdrawal, $postedBy, $on, $movedAt, $drawings, $stock) {
            $withdrawal = OwnerWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->with(['lines.product', 'proprietor'])->firstOrFail();

            if (! $withdrawal->isApproved()) {
                throw new RuntimeException("Owner withdrawal {$withdrawal->number} changed a moment ago.");
            }

            $batches = StockBatch::query()->whereIn('id', $withdrawal->lines->pluck('stock_batch_id')->filter())->lockForUpdate()->get()->keyBy('id');
            $withdrawal->lines->each(fn (OwnerWithdrawalLine $line) => $line->setRelation('batch', $batches->get($line->stock_batch_id)));

            if (($reason = $this->refusal($withdrawal, $on)) !== null) {
                throw new RuntimeException("Owner withdrawal {$withdrawal->number} cannot be posted: {$reason}");
            }

            // The hold is consumed by the movement that follows: release it
            // first so the batch's free quantity and its balance agree.
            $this->release->forReference($withdrawal);

            $lines = [];
            $costTotal = Money::zero();

            foreach ($withdrawal->lines as $line) {
                /** @var OwnerWithdrawalLine $line */
                $batch = $line->batch;

                $movement = StockMovement::create([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $withdrawal->warehouse_id,
                    'batch_id' => $batch->id,
                    'direction' => MovementDirection::Out,
                    'reason' => MovementReason::OwnerWithdrawal,
                    'reason_id' => null,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => (string) $batch->unit_cost,
                    'performed_by' => $postedBy->id,
                    'note' => "Owner withdrawal {$withdrawal->number}",
                    'occurred_at' => $movedAt,
                ]);

                $cost = Money::ofProduct((string) $line->quantity, (string) $batch->unit_cost);
                $costTotal = $costTotal->plus($cost);

                $line->update(['stock_movement_id' => $movement->id, 'cost_paisa' => $cost->minor]);

                $lines[] = JournalLineDraft::credit($stock->id, $cost, "{$withdrawal->number}: {$line->product?->name} batch {$batch->batch_number}");
            }

            array_unshift($lines, JournalLineDraft::debit($drawings->id, $costTotal, "{$withdrawal->number}: goods taken by {$withdrawal->proprietor?->name} at cost"));

            $journal = $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: $lines,
                memo: "Owner withdrawal {$withdrawal->number}: stock taken by the proprietor at cost (drawings, not a sale)",
                source: $withdrawal,
            ), $postedBy);

            $withdrawal->update([
                'status' => OwnerWithdrawalStatus::Posted,
                'journal_id' => $journal->id,
                'posted_at' => now(),
                'posted_by' => $postedBy->id,
                'cost_total_paisa' => $costTotal->minor,
            ]);

            return $withdrawal->fresh(['lines.product', 'lines.batch', 'lines.movement', 'journal']);
        });
    }

    /** Why the authorised batches can no longer be issued as authorised, or null when they can. */
    private function refusal(OwnerWithdrawal $withdrawal, Carbon $on): ?string
    {
        foreach ($withdrawal->lines as $line) {
            /** @var OwnerWithdrawalLine $line */
            $batch = $line->batch;
            $name = $line->product?->name ?? "product #{$line->product_id}";

            if ($batch === null || $line->unit_cost === null) {
                return "{$name} has no reserved batch.";
            }

            if ($batch->isHeld()) {
                return "batch {$batch->batch_number} of {$name} is on hold.";
            }

            if ($batch->expiry_date !== null && $batch->expiry_date->lt(now()->startOfDay())) {
                return "batch {$batch->batch_number} of {$name} has expired.";
            }

            if ((float) $batch->current_quantity + 0.00001 < (float) $line->quantity) {
                return "batch {$batch->batch_number} of {$name} holds only {$batch->current_quantity} of the {$line->quantity} authorised.";
            }

            if (abs((float) $batch->unit_cost - (float) $line->unit_cost) > 0.00001) {
                return "batch {$batch->batch_number} of {$name} is now costed at {$batch->unit_cost}, not the {$line->unit_cost} authorised.";
            }

            if ($batch->received_at !== null && $on->lt($batch->received_at->copy()->startOfDay())) {
                return "batch {$batch->batch_number} of {$name} was received after the withdrawal date.";
            }
        }

        return null;
    }
}
