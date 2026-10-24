<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026, FINAL-PLAN 3.3 "Purchase return:
 * Reverse"): a purchase return recorded by mistake, undone.
 *
 * Three things move together, in one transaction:
 *   1. the stock: every batch the return relieved gets its goods back, through
 *      RestoreStockIssue, on the SAME batch at the SAME cost (the movement says
 *      which return movement it undoes);
 *   2. the ledger: the return's journal (Dr GRNI / Cr Stock on Hand) mirrored
 *      through ReverseJournal on the date the corrections process resolved;
 *   3. the order: `quantity_returned` given back, so the goods are billable
 *      again exactly as they were before the return.
 * The return is KEPT and marked reversed.
 *
 * ⚠ Refused while a live supplier credit note settles it (that note is
 * reversed first), and while a batch's cost has moved since (a landed cost or
 * a discount revalued it): putting the goods back at today's cost would make
 * the stock ledger and the mirrored journal disagree by the difference.
 */
class ReversePurchaseReturn
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly PostPurchaseJournals $posting,
        private readonly RestoreStockIssue $restore,
    ) {}

    /**
     * Why this return cannot be reversed, in one sentence, or null.
     */
    public function refusal(PurchaseReturn $return): ?string
    {
        if ($return->isReversed()) {
            return "{$return->label()} was already reversed.";
        }

        return $this->creditRefusal($return) ?? $this->stockRefusal($return);
    }

    /** A live supplier credit note settles the return: it is reversed first. */
    public function creditRefusal(PurchaseReturn $return): ?string
    {
        $note = SupplierCreditNote::query()->where('purchase_return_id', $return->id)->whereNull('reversed_at')->value('credit_note_number');

        if ($note !== null) {
            return "Credit note {$note} credits {$return->label()}. Reverse the credit note first.";
        }

        // ⭐ Initial plan B9 (Joy's Q21, 25 Oct 2026): the credit a paid bill
        // could not take, kept as a supplier advance, is reversed first too.
        $advance = SupplierAdvance::query()->where('purchase_return_id', $return->id)->whereNull('reversed_at')->value('number');

        return $advance === null ? null : "Advance {$advance} keeps the credit for {$return->label()}. Reverse the advance first.";
    }

    /** Every batch the return relieved is still there, at the cost it left at. */
    public function stockRefusal(PurchaseReturn $return): ?string
    {
        foreach ($return->lines()->with('stockMovement')->get() as $line) {
            /** @var PurchaseReturnLine $line */
            $movement = $line->stockMovement;

            if ($movement === null) {
                continue;
            }

            $batch = StockBatch::query()->find($movement->batch_id);

            if ($batch === null) {
                return "A batch that {$return->label()} took goods from no longer exists. Ask the administrator to check it.";
            }

            if ($this->scaled((string) $batch->unit_cost) !== $this->scaled((string) $movement->unit_cost)) {
                return "Batch {$batch->batch_number} has had its cost changed since the return. Record the goods as a new receipt instead.";
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function handle(PurchaseReturn $return, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null): PurchaseReturn
    {
        $reason = trim($reason);
        $on = $on->copy()->startOfDay();

        AccountingPeriod::assertOpenFor($on, $pass);

        return DB::transaction(function () use ($return, $by, $reason, $on, $pass) {
            $return = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if (($refusal = $this->refusal($return)) !== null) {
                throw ValidationException::withMessages(['correction' => $refusal]);
            }

            $journal = $this->posting->existingJournal($return);
            $reversal = $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, "Reversal of {$return->label()}: {$reason}", $pass);

            $lines = $return->lines()->with('stockMovement')->orderBy('id')->get();
            $restorations = $lines->filter(fn (PurchaseReturnLine $line) => $line->stockMovement !== null)
                ->map(fn (PurchaseReturnLine $line) => ['movement' => $line->stockMovement, 'quantity' => (string) $line->stockMovement->quantity])
                ->values()->all();

            if ($restorations !== []) {
                $this->restore->handle($restorations, $by, "Reversal of {$return->label()}: {$reason}", MovementReason::PurchaseReturn, $on);
            }

            // The order lines get back what this return took, under a lock.
            foreach ($lines->groupBy('purchase_order_line_id') as $orderLineId => $rows) {
                $orderLine = PurchaseOrderLine::query()->whereKey($orderLineId)->lockForUpdate()->firstOrFail();
                $back = $rows->sum(fn (PurchaseReturnLine $line) => $this->scaled((string) $line->quantity_returned));
                $orderLine->update(['quantity_returned' => $this->unscaled(max(0, $this->scaled((string) $orderLine->quantity_returned) - $back))]);
            }

            $return->forceFill([
                'reversed_on' => $on,
                'reversed_at' => now(),
                'reversed_by' => $by->id,
                'reversal_reason' => $reason,
                'reversal_journal_id' => $reversal?->id,
            ])->save();

            activity()->performedOn($return)->causedBy($by)
                ->withProperties(array_filter([
                    'reason' => $reason,
                    'reversed_on' => $on->toDateString(),
                    'journal' => $journal?->journal_number,
                    'reversal_journal' => $reversal?->journal_number,
                ], fn ($value) => $value !== null))
                ->log('reversed');

            return $return->fresh();
        });
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
