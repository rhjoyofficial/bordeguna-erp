<?php

namespace App\Modules\Sales\Actions\Sellers;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Actions\ReverseStockReceipt;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Models\SellerTransfer;
use App\Modules\Sales\Models\SellerTransferLine;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ Initial plan B21: a hand-over or a seller return taken back, as if it had
 * never been written (the engine's Reverse, SellerTransferCorrector).
 *
 * For every line: the goods come back OUT of the batches the sheet made at
 * its destination (ReverseStockReceipt on the transfer's arriving legs), and
 * go back ONTO the exact batches they left, at the cost they left at
 * (RestoreStockIssue on the departing legs). No journal: a transfer posted
 * none.
 *
 * ⚠⚠ Refused while any of those goods have moved on: sold from his seller
 * stock, brought back by a return, or used at the warehouse after a return.
 * Taking them back regardless would make stock that is no longer there. The
 * sentence says what to do instead (void the sale, reverse the return, or
 * take the rest back with a return of unsold goods).
 *
 * The caller (the engine) holds the transaction and the locks.
 */
class ReverseSellerTransfer
{
    public function __construct(
        private readonly ReverseStockReceipt $takeBack,
        private readonly RestoreStockIssue $restore,
    ) {}

    /** The first sentence that stops a reversal, or null. */
    public static function blocker(SellerTransfer $sheet): ?string
    {
        foreach (self::legs($sheet) as $leg) {
            foreach ($leg['in'] as $in) {
                $batch = StockBatch::query()->find($in->batch_id);

                if ($batch === null || (float) $batch->current_quantity + 0.00005 < (float) $in->quantity) {
                    $product = $leg['line']->product?->name ?? 'Some of the goods';

                    return $sheet->isHandover()
                        ? "{$product} from {$sheet->number} was already sold or brought back. Void those sales or reverse the return first, or take the rest back with a return of unsold goods."
                        : "{$product} returned on {$sheet->number} was already used at the warehouse, so the return cannot be taken back. Hand it over again instead.";
                }
            }
        }

        return null;
    }

    public function handle(SellerTransfer $sheet, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null): SellerTransfer
    {
        if ($sheet->isReversed()) {
            throw new DomainException("{$sheet->label()} was already reversed.");
        }

        if (($blocked = self::blocker($sheet)) !== null) {
            throw new DomainException($blocked);
        }

        $note = "{$sheet->label()} reversed: {$reason}";

        foreach (self::legs($sheet) as $leg) {
            foreach ($leg['in'] as $in) {
                $at = $this->when($in, $on);
                $this->takeBack->handle([$in], (string) $in->quantity, $by, $note, MovementReason::Transfer, $at, $pass);
            }

            $this->restore->handle(
                restorations: $leg['out']->map(fn (StockMovement $out) => ['movement' => $out, 'quantity' => (string) $out->quantity])->all(),
                performedBy: $by,
                note: $note,
                reason: MovementReason::Transfer,
                occurredAt: $this->when($leg['in']->first() ?? $leg['out']->first(), $on),
                pass: $pass,
            );
        }

        $sheet->forceFill([
            'reversed_on' => $on->toDateString(),
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
        ])->save();

        return $sheet->fresh(['lines']);
    }

    /**
     * On the sheet's own day the movements land at the sheet's own moment (a
     * bare date would be midnight, before the batch existed); on a later day,
     * at its end.
     */
    private function when(StockMovement $movement, Carbon $on): Carbon
    {
        $original = Carbon::parse($movement->occurred_at);

        return $original->isSameDay($on) ? $original : $on->copy()->setTime(23, 59, 59);
    }

    /**
     * @return list<array{line: SellerTransferLine, in: Collection<int, StockMovement>, out: Collection<int, StockMovement>}>
     */
    private static function legs(SellerTransfer $sheet): array
    {
        $legs = [];

        foreach ($sheet->lines()->with(['product:id,name', 'stockTransfer:id,transfer_group_id'])->orderBy('id')->get() as $line) {
            $group = $line->stockTransfer?->transfer_group_id;

            if ($group === null) {
                continue;
            }

            $moves = StockMovement::query()->where('transfer_group_id', $group)->whereNull('reverses_movement_id')->orderBy('id')->get();
            $legs[] = [
                'line' => $line,
                'in' => $moves->where('direction', MovementDirection::In)->values(),
                'out' => $moves->where('direction', MovementDirection::Out)->values(),
            ];
        }

        return $legs;
    }
}
