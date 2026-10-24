<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Hrm\Models\StaffPurchaseLine;
use App\Modules\Hrm\Models\StaffPurchaseReplacement;
use App\Modules\Hrm\Models\StaffPurchaseRequest;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ "Replace (defect)" on a fulfilled staff purchase (office order: a defect
 * is exchanged, no returns; ChatGPT ruling D7, Phase 2, 17 Oct 2026).
 *
 * A LINKED, ZERO-CHARGE replacement: the replacement unit leaves stock from
 * FIFO at its batch cost with reason `staff_replacement`, and posts
 *   Dr the damaged-goods write-off head (`inventory.adjustment.damaged`)
 *       Cr Stock on Hand
 * No new invoice, no second charge, no quota used; the defective unit the
 * employee hands back is written off on the replacement row and never goes
 * back on the shelf. Needs `hrm.employees.manage`, a reason and an evidence
 * note, and stays linked to the original request and line.
 */
class ReplaceStaffPurchaseDefect
{
    public function __construct(
        private readonly RecordStockOut $stockOut,
        private readonly PostJournal $postJournal,
    ) {}

    /** @throws ValidationException */
    public function handle(StaffPurchaseRequest $request, StaffPurchaseLine $line, string $reason, string $evidenceNote, User $by): StaffPurchaseReplacement
    {
        if ($request->status !== StaffPurchaseRequest::FULFILLED) {
            throw ValidationException::withMessages([
                'request' => "Application {$request->number} is {$request->status}; only a handed-over purchase can be replaced.",
            ]);
        }

        if ((int) $line->request_id !== (int) $request->id) {
            throw ValidationException::withMessages(['line' => 'That line belongs to a different application.']);
        }

        if (trim($reason) === '' || trim($evidenceNote) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A replacement needs the reason and a note of the evidence seen (photo, the jar, the batch).',
            ]);
        }

        $alreadyReplaced = (float) StaffPurchaseReplacement::query()->where('line_id', $line->id)->sum('quantity');

        if ($alreadyReplaced + 0.00005 >= (float) $line->quantity) {
            throw ValidationException::withMessages([
                'line' => 'Every unit on that line has already been replaced once.',
            ]);
        }

        return DB::transaction(function () use ($request, $line, $reason, $evidenceNote, $by) {
            $warehouse = Warehouse::query()->whereKey($request->warehouse_id)->first()
                ?? Warehouse::query()->saleable()->orderByDesc('is_default')->firstOrFail();

            $movements = $this->stockOut->handle(
                product: $line->product,
                warehouse: $warehouse,
                quantity: (string) $line->quantity,
                performedBy: $by,
                note: "Defect replacement on staff purchase {$request->number}",
                reason: MovementReason::StaffReplacement,
            );

            $cost = Money::sum($movements->map(fn ($movement) => StockValue::of($movement)->absolute()));
            $unitCost = (float) $line->quantity > 0 ? $cost->minor / 100 / (float) $line->quantity : 0.0;

            $replacement = StaffPurchaseReplacement::create([
                'business_id' => $request->business_id,
                'request_id' => $request->id,
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'quantity' => (string) $line->quantity,
                'unit_cost' => number_format($unitCost, 4, '.', ''),
                'reason' => trim($reason),
                'evidence_note' => trim($evidenceNote),
                'defective_disposition' => StaffPurchaseReplacement::DISPOSITION_WRITE_OFF,
                'stock_movement_id' => $movements->first()?->id,
                'created_by' => $by->id,
            ]);

            $journal = $this->post($replacement, $cost, $by);

            if ($journal !== null) {
                $replacement->forceFill(['journal_id' => $journal->id])->save();
            }

            $request->record('replaced', $by, [
                'line_id' => $line->id,
                'product_id' => $line->product_id,
                'quantity' => (string) $line->quantity,
                'cost' => $cost->toTaka(),
                'reason' => trim($reason),
                'evidence' => trim($evidenceNote),
                'replacement_id' => $replacement->id,
            ]);

            return $replacement->fresh();
        });
    }

    private function post(StaffPurchaseReplacement $replacement, Money $cost, User $by)
    {
        $on = Carbon::today();

        if ($cost->isZero() || AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
        $loss = AccountMapping::resolve('inventory.adjustment.damaged', $on, 'inventory.adjustment.default');

        $lines = LedgerRounding::balance([
            JournalLineDraft::debit($loss->id, $cost, 'Staff purchase defect replacement'),
            JournalLineDraft::credit($stock->id, $cost, 'Replacement unit issued at cost'),
        ], $on, "Rounding on staff replacement #{$replacement->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Staff purchase defect replacement #{$replacement->id}",
            source: $replacement,
        ), $by);
    }
}
