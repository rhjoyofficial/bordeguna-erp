<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * C-MFG-8's correction path: a confirmed run is immutable, and undoing it is a
 * new set of movements that say so - never an edit.
 *
 * The reversal re-creates the consumed portions on their exact original
 * batches at their frozen costs (RestoreStockIssue), consumes the produced
 * batch through the layer it created (ReverseStockReceipt), and reverses the
 * journal if one existed. Same numbers, mirrored, so the ledger and the
 * subledger move back together.
 *
 * ⚠⚠ REFUSED once the produced batch has been touched. Reversing a run whose
 * packs are partly sold would need those sales unwound too; the honest tool
 * for a partly-consumed mistake is an inventory adjustment, and the refusal
 * says so.
 */
class ReverseProductionRun
{
    public function __construct(
        private readonly RestoreStockIssue $restoreStockIssue,
        private readonly ReverseStockReceipt $reverseStockReceipt,
        private readonly ReverseJournal $reverseJournal,
        private readonly PostProductionRunJournal $posting,
    ) {}

    public function handle(ProductionRun $run, User $reversedBy, ?string $note = null): ProductionRun
    {
        if (! $run->isConfirmed()) {
            throw new RuntimeException("Production run {$run->run_number} is {$run->status->value} and cannot be reversed.");
        }

        $batch = $run->outputBatch;

        if ($batch === null) {
            throw new RuntimeException("Production run {$run->run_number} has no output batch to reverse.");
        }

        // ⚠ Untouched means untouched: the batch still holds every unit the run
        // produced. `current < initial` is a sale or an adjustment somebody
        // already made on top of this run, and unwinding that is not this
        // action's call.
        if ($this->scaled((string) $batch->current_quantity) !== $this->scaled((string) $batch->initial_quantity)) {
            throw new DomainException(
                "Production run {$run->run_number} cannot be reversed: its output batch has already been "
                .'partly sold or consumed. Correct the remainder with an inventory adjustment instead.'
            );
        }

        // ⚠ Dated TODAY, like ReverseJournal: back-dating a reversal quietly
        // changes a period that may already have been reported. The period lock
        // has the final say either way.
        $reversedAt = EffectiveDate::resolve(null);

        return DB::transaction(function () use ($run, $reversedBy, $note, $reversedAt) {
            $note = $note ?? "Reversal of production run {$run->run_number}";

            $produced = $run->movements()
                ->where('direction', MovementDirection::In)
                ->whereNull('reverses_movement_id')
                ->get();

            $consumed = $run->movements()
                ->where('direction', MovementDirection::Out)
                ->whereNull('reverses_movement_id')
                ->get();

            // Take the produced units back out through the exact layer the run
            // created - never FIFO, which could relieve somebody else's batch.
            $reversedOut = $this->reverseStockReceipt->handle(
                receipts: $produced,
                quantity: (string) $run->output_quantity,
                performedBy: $reversedBy,
                note: $note,
                reason: MovementReason::Production,
                occurredAt: $reversedAt,
            );

            // Put every consumed portion back onto the batch it came from, at
            // the frozen cost it left at - full quantity, movement by movement.
            $restored = $this->restoreStockIssue->handle(
                restorations: $consumed->map(fn (StockMovement $movement) => [
                    'movement' => $movement,
                    'quantity' => (string) $movement->quantity,
                ])->all(),
                performedBy: $reversedBy,
                note: $note,
                reason: MovementReason::Production,
                occurredAt: $reversedAt,
            );

            $movementIds = $reversedOut->map(fn ($reversed) => $reversed->movement->id)
                ->merge($restored->pluck('id'))
                ->all();

            StockMovement::whereIn('id', $movementIds)->update(['production_run_id' => $run->id]);

            // The mirror image of whatever the run posted - which is usually
            // nothing, correctly. ReverseJournal re-faces every guard the
            // original faced, including the period lock.
            $journal = $this->posting->existingJournal($run);

            if ($journal !== null) {
                $this->reverseJournal->handle($journal, $reversedBy);
            }

            $run->update([
                'status' => ProductionRunStatus::Reversed,
                'reversed_by' => $reversedBy->id,
                'reversed_at' => Carbon::now(),
            ]);

            return $run->fresh(['lines.product', 'movements', 'outputBatch', 'outputProduct']);
        });
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }
}
