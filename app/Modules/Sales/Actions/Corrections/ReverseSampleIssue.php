<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Models\SampleIssue;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ A given sample taken back, as if it had never left (Samples, 25 Oct 2026;
 * the engine's Reverse, SampleIssueCorrector).
 *
 *   stock    every movement put back ONTO the batch it left, at the cost it
 *            left at (RestoreStockIssue, reason `sample`, naming the movement)
 *   journal  the sample's journal mirrored (ReverseJournal): Stock on Hand up,
 *            Samples and Marketing down, and the delivery charge back to the
 *            money account or off the courier's payable
 *   CRM      its open feedback follow-up is cancelled
 *
 * ⚠⚠ Refused while a batch's cost has changed since (a landed cost, a
 * revaluation): the goods would come back at the batch's current cost while
 * the mirror journal gives Stock on Hand the cost they left at, and the stock
 * and the ledger would part by the difference (ReverseOwnerWithdrawal's rule).
 *
 * The caller (the engine) holds the transaction and the locks.
 */
class ReverseSampleIssue
{
    public function __construct(
        private readonly RestoreStockIssue $restore,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    /** The first sentence that stops a reversal, or null. */
    public static function blocker(SampleIssue $issue): ?string
    {
        if ($issue->isReversed()) {
            return "Sample {$issue->number} was already reversed.";
        }

        if (! $issue->isPosted()) {
            return "Sample {$issue->number} was never given (it is not approved), so there is nothing to reverse.";
        }

        foreach (self::movements($issue) as $movement) {
            $batch = StockBatch::query()->find($movement->batch_id);
            $name = $movement->product?->name ?? "product #{$movement->product_id}";

            if ($batch === null) {
                return "{$name} on {$issue->number} has no batch to go back to. Ask the administrator to check it.";
            }

            if (StockMovement::query()->where('reverses_movement_id', $movement->id)->exists()) {
                return "{$name} on {$issue->number} was already put back. Ask the administrator to check it.";
            }

            if (bccomp((string) $batch->unit_cost, (string) $movement->unit_cost, 4) !== 0) {
                return "Batch {$batch->batch_number} of {$name} was re-costed since, so the goods cannot go back at the cost they left at.";
            }
        }

        return null;
    }

    public function handle(SampleIssue $issue, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null): ?Journal
    {
        if (($blocked = self::blocker($issue)) !== null) {
            throw new DomainException($blocked);
        }

        $note = "Sample {$issue->number} reversed: {$reason}";
        $movements = self::movements($issue);

        if ($movements->isNotEmpty()) {
            $this->restore->handle(
                restorations: $movements->map(fn (StockMovement $out) => ['movement' => $out, 'quantity' => (string) $out->quantity])->values()->all(),
                performedBy: $by,
                note: mb_substr($note, 0, 250),
                reason: MovementReason::Sample,
                occurredAt: $this->when($movements->first(), $on),
                pass: $pass,
            );
        }

        $journal = $issue->journal_id === null ? null : Journal::query()->find($issue->journal_id);
        $reversal = $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, mb_substr($note, 0, 250), $pass);

        if ($issue->crm_task_id !== null) {
            CrmTask::query()->whereKey($issue->crm_task_id)->where('status', TaskStatus::Open)->update(['status' => TaskStatus::Cancelled]);
        }

        $issue->forceFill([
            'reversed_on' => $on->toDateString(),
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();

        return $reversal;
    }

    /**
     * The OUT movements the sample wrote, oldest first.
     *
     * @return Collection<int, StockMovement>
     */
    public static function movements(SampleIssue $issue): Collection
    {
        return StockMovement::query()
            ->whereIn('id', fn ($q) => $q->select('stock_movement_id')->from('sample_issue_line_movements')
                ->whereIn('sample_issue_line_id', fn ($l) => $l->select('id')->from('sample_issue_lines')->where('sample_issue_id', $issue->id)))
            ->whereNull('reverses_movement_id')
            ->with('product:id,name')
            ->orderBy('id')
            ->get();
    }

    /** On the sample's own day the goods come back at the moment they left; on a later day, at its end. */
    private function when(StockMovement $movement, Carbon $on): Carbon
    {
        $original = Carbon::parse($movement->occurred_at);

        return $original->isSameDay($on) ? $original : $on->copy()->setTime(23, 59, 59);
    }
}
