<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetHolding;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetMovementLine;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a hand-over put right (FINAL-PLAN
 * 3.5, "Hand-over").
 *
 *   reverse   a DONE hand-over that is still the latest movement of every
 *             thing on it, within 7 days: a NEW movement, the mirror of the
 *             original (from and to swapped, the opposite kind), linked to it
 *             and dated on the original's own day. Each thing gets back
 *             exactly what its line recorded before it moved (custody, place,
 *             status, condition); a counted group's pieces go back to the
 *             holder they left. The original is never edited: it keeps its
 *             number and is marked Reversed, with who, when and why.
 *   cancel    a transfer still ON ITS WAY: the things never left their place,
 *             so nothing new is written; the transfer is marked Cancelled.
 *
 * ⚠⚠ "Never edited: a mistake is another movement" (slice A2) still holds:
 * the reversal IS another movement, and both stay in the custody history.
 *
 * ⚠ All or nothing, under the items' locks, re-checked at the last moment
 * (FINAL-PLAN 6.6, items 2 and 7). `reverses_movement_id` is UNIQUE, so a
 * double click can never write a second reversal.
 */
final class ReverseAssetMovement
{
    private const OPPOSITE = [
        'issue' => AssetMovementKind::Return,
        'return' => AssetMovementKind::Issue,
        'send_out' => AssetMovementKind::ReceiveBack,
        'receive_back' => AssetMovementKind::SendOut,
        'transfer' => AssetMovementKind::Transfer,
        'reassign' => AssetMovementKind::Reassign,
    ];

    /**
     * Why this movement cannot be reversed now, or null. Asked by the policy
     * for the button and again here under the lock.
     */
    public static function blocker(AssetMovement $movement): ?string
    {
        if ($movement->isReversal()) {
            return "{$movement->number} is itself a reversal. Record a new hand-over instead.";
        }

        if ($movement->isReversed()) {
            return $movement->isCancelled() ? "{$movement->number} was already cancelled." : "{$movement->number} was already reversed.";
        }

        if ($movement->status === AssetMovementStatus::Dispatched) {
            return "{$movement->number} is still on its way. Cancel it instead.";
        }

        if ($movement->created_at === null || $movement->created_at->copy()->addDays(AssetMovement::REVERSE_DAYS)->isPast()) {
            return "{$movement->number} is more than ".AssetMovement::REVERSE_DAYS.' days old. Record the opposite hand-over instead.';
        }

        foreach ($movement->lines()->with('item')->get() as $line) {
            $item = $line->item;

            if ($line->before === null) {
                return "{$movement->number} was recorded before reversals were possible. Record the opposite hand-over instead.";
            }

            if ($item === null || $item->trashed()) {
                return "A thing on {$movement->number} is in the Recycle bin.";
            }

            if (($later = self::laterMovement($item, $movement)) !== null) {
                return "{$item->tag} moved again on {$later->number}. Reverse that one first.";
            }

            if ($item->status === AssetStatus::Disposed) {
                return "{$item->tag} was disposed since. Undo the disposal first.";
            }
        }

        return null;
    }

    /** Why a transfer on its way cannot be cancelled, or null. */
    public static function cancelBlocker(AssetMovement $movement): ?string
    {
        if ($movement->kind !== AssetMovementKind::Transfer) {
            return "{$movement->number} is not a transfer between places.";
        }

        if ($movement->status === AssetMovementStatus::Cancelled) {
            return "{$movement->number} was already cancelled.";
        }

        if ($movement->status !== AssetMovementStatus::Dispatched) {
            return "{$movement->number} has arrived, so it cannot be cancelled. Reverse it instead.";
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    public function reverse(AssetMovement $movement, User $by, ActionReason $why): AssetMovement
    {
        return DB::transaction(function () use ($movement, $by, $why) {
            /** @var AssetMovement $locked */
            $locked = AssetMovement::query()->withoutGlobalScopes()->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $lines = $locked->lines()->get();

            // ⚠ The things first, under their locks, then every rule again.
            $items = AssetItem::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('asset_item_id'))->lockForUpdate()->get()->keyBy('id');

            if (($refusal = self::blocker($locked)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $refusal]);
            }

            $reversal = AssetMovement::create([
                'business_id' => $locked->business_id,
                'number' => self::nextNumber($locked->business_id),
                'kind' => self::OPPOSITE[$locked->kind->value],
                'status' => AssetMovementStatus::Completed,
                'from_office_id' => $locked->to_office_id,
                'from_warehouse_id' => $locked->to_warehouse_id,
                'from_employee_id' => $locked->to_employee_id,
                'from_party_id' => $locked->to_party_id,
                'to_office_id' => $locked->from_office_id,
                'to_warehouse_id' => $locked->from_warehouse_id,
                'to_employee_id' => $locked->from_employee_id,
                'to_party_id' => $locked->from_party_id,
                // The original date rule (FINAL-PLAN section 9, answer 4).
                'moved_on' => $locked->moved_on,
                'note' => "Reversal of {$locked->number}: {$why->display()}",
                'created_by' => $by->id,
                'reverses_movement_id' => $locked->id,
            ]);

            foreach ($lines as $line) {
                /** @var AssetItem $item */
                $item = $items[$line->asset_item_id];

                $reversal->lines()->create([
                    'asset_item_id' => $item->id,
                    'quantity' => $line->quantity,
                    'condition' => null,
                    'before' => array_intersect_key($item->getAttributes(), array_flip(AssetMovementLine::BEFORE)),
                ]);

                if (! $item->isIndividual()) {
                    // The pieces leave whoever they reached and go back.
                    if ($locked->kind !== AssetMovementKind::Transfer || $locked->status === AssetMovementStatus::Completed) {
                        self::adjustHolding($item, $locked->to_office_id, $locked->to_warehouse_id, $locked->to_employee_id, $locked->to_party_id, -$line->quantity);
                    }

                    self::adjustHolding($item, $locked->from_office_id, $locked->from_warehouse_id, $locked->from_employee_id, $locked->from_party_id, $line->quantity);
                }

                // ⚠ Exactly what the line recorded, nothing guessed.
                $item->forceFill(self::restorable($line->before))->save();

                activity('assets.items')->performedOn($item)->causedBy($by)
                    ->log("{$locked->number} reversed by {$reversal->number}: {$why->display()}");
            }

            $locked->forceFill(['reversed_at' => Carbon::now(), 'reversed_by' => $by->id, 'reversal_reason' => $why->display()])->save();

            CorrectionLog::event('reversed', $by, $locked, ['reason' => $why->display(), 'number' => $locked->number, 'reversal' => $reversal->number]);

            return $reversal->fresh('lines');
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(AssetMovement $movement, User $by, ActionReason $why): AssetMovement
    {
        return DB::transaction(function () use ($movement, $by, $why) {
            /** @var AssetMovement $locked */
            $locked = AssetMovement::query()->withoutGlobalScopes()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if (($refusal = self::cancelBlocker($locked)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $refusal]);
            }

            foreach ($locked->lines()->get() as $line) {
                /** @var AssetItem $item */
                $item = AssetItem::query()->withoutGlobalScopes()->whereKey($line->asset_item_id)->lockForUpdate()->firstOrFail();

                if ($item->isIndividual()) {
                    // It never left: only "on its way" (and the status the
                    // dispatch set) goes back.
                    $item->forceFill($line->before !== null
                        ? self::restorable($line->before)
                        : ['transit_movement_id' => null])->save();
                } else {
                    self::adjustHolding($item, $locked->from_office_id, $locked->from_warehouse_id, null, null, $line->quantity);

                    if ($item->transit_movement_id === $locked->id) {
                        $item->forceFill(['transit_movement_id' => null])->save();
                    }
                }

                activity('assets.items')->performedOn($item)->causedBy($by)
                    ->log("{$locked->number} cancelled before it arrived: {$why->display()}");
            }

            $locked->forceFill([
                'status' => AssetMovementStatus::Cancelled,
                'reversed_at' => Carbon::now(),
                'reversed_by' => $by->id,
                'reversal_reason' => $why->display(),
            ])->save();

            CorrectionLog::event('cancelled', $by, $locked, ['reason' => $why->display(), 'number' => $locked->number]);

            return $locked->fresh('lines');
        });
    }

    /** A movement of this thing recorded after `$movement`, or null. */
    public static function laterMovement(AssetItem $item, AssetMovement $movement): ?AssetMovement
    {
        return AssetMovement::query()->withoutGlobalScopes()
            ->where('id', '>', $movement->id)
            ->where(fn ($q) => $q->whereNull('reverses_movement_id')->orWhere('reverses_movement_id', '!=', $movement->id))
            ->whereHas('lines', fn ($q) => $q->where('asset_item_id', $item->id))
            ->orderBy('id')
            ->first();
    }

    /**
     * The recorded columns, as the item expects them.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private static function restorable(array $before): array
    {
        return array_intersect_key($before, array_flip(AssetMovementLine::BEFORE));
    }

    private static function adjustHolding(AssetItem $item, ?int $officeId, ?int $warehouseId, ?int $employeeId, ?int $partyId, int $delta): void
    {
        $key = AssetHolding::keyFor($officeId, $warehouseId, $employeeId, $partyId);

        /** @var AssetHolding $holding */
        $holding = AssetHolding::query()->withoutGlobalScopes()->firstOrCreate(
            ['asset_item_id' => $item->id, 'holder_key' => $key],
            ['business_id' => $item->business_id, 'office_id' => $officeId, 'warehouse_id' => $warehouseId, 'employee_id' => $employeeId, 'party_id' => $partyId, 'quantity' => 0],
        );

        $next = $holding->quantity + $delta;

        if ($next < 0) {
            throw ValidationException::withMessages(['reason_text' => "{$item->tag}: not that many are there any more, so the hand-over cannot be reversed."]);
        }

        if ($next === 0) {
            $holding->delete();
        } else {
            $holding->forceFill(['quantity' => $next])->save();
        }
    }

    private static function nextNumber(int $businessId): string
    {
        // The same numbering as MoveAssets: one more than the highest.
        $last = (int) AssetMovement::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->selectRaw('max(cast(substr(number, 4) as signed)) as n')
            ->value('n');

        return sprintf('AM-%06d', $last + 1);
    }
}
