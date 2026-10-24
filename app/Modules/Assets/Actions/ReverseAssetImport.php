<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Models\AssetImportBatch;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetMovementLine;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a things sheet imported by mistake
 * (or twice) is taken back in one step (FINAL-PLAN 3.5, "Thing import batch:
 * Reverse import. Within 7 days, untouched things only"; example 3 in
 * section 2: Moin imported the chairs twice).
 *
 * What it does, all or nothing:
 *   1. The hand-overs the sheet itself recorded (a row that named a holder)
 *      are reversed through ReverseAssetMovement, so custody history shows
 *      both and the holder no longer has the thing.
 *   2. Every thing the sheet registered that nobody touched since goes to the
 *      Recycle bin, its tag still reserved.
 *   3. A thing that WAS used since (handed over again, counted, repaired, a
 *      login, a condition photo, a part) is KEPT, with its reason, and so is
 *      every thing that shares a sheet hand-over with it. The person handles
 *      those by hand. The preview lists both before anything is done.
 *
 * ⚠ The rows the sheet UPDATED (existing things it changed) keep their
 * changes: an update is not a registration, and the sheet kept no "before".
 * The preview says so.
 */
final class ReverseAssetImport
{
    public function __construct(private readonly ReverseAssetMovement $movements) {}

    /** Why the batch cannot be reversed at all, or null. */
    public static function blocker(AssetImportBatch $batch): ?string
    {
        if ($batch->isReversed()) {
            return "Import {$batch->number} was already reversed.";
        }

        if (! $batch->isWithinWindow()) {
            return "Import {$batch->number} is more than ".AssetImportBatch::REVERSE_DAYS.' days old. Delete or dispose of its things one by one.';
        }

        if ($batch->created_count === 0) {
            return "Import {$batch->number} registered no new things, so there is nothing to take back.";
        }

        return null;
    }

    /**
     * What a reversal would do now: the things it removes and the things it
     * keeps, each with the reason. Pure: writes nothing.
     *
     * @return array{remove: list<array{id: int, tag: string, name: string}>, keep: list<array{id: int, tag: string, name: string, why: string}>, updated: int}
     */
    public function plan(AssetImportBatch $batch): array
    {
        $items = AssetItem::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('import_batch_id', $batch->id)->orderBy('tag')->get();
        $sheetMovements = AssetMovement::query()->withoutGlobalScopes()->where('import_batch_id', $batch->id)->pluck('id')->all();
        $keep = [];

        foreach ($items as $item) {
            if (($why = $this->whyKept($item, $sheetMovements)) !== null) {
                $keep[$item->id] = $why;
            }
        }

        // ⚠ A sheet hand-over is reversed whole or not at all: its other
        // things stay with it.
        $standing = AssetMovement::query()->withoutGlobalScopes()->whereIn('id', $sheetMovements)->whereNull('reversed_at')->pluck('id');

        foreach ($standing as $movementId) {
            $onIt = AssetMovementLine::query()->where('asset_movement_id', $movementId)->pluck('asset_item_id')->all();
            $blocked = array_values(array_filter($onIt, fn ($id) => isset($keep[$id])));

            if ($blocked !== []) {
                $tag = $items->firstWhere('id', $blocked[0])?->tag;

                foreach ($onIt as $id) {
                    $keep[$id] ??= "It was handed over together with {$tag}, which is kept.";
                }
            }
        }

        $shape = fn (AssetItem $item) => ['id' => $item->id, 'tag' => $item->tag, 'name' => $item->name];

        return [
            'remove' => $items->reject(fn (AssetItem $item) => isset($keep[$item->id]))->map($shape)->values()->all(),
            'keep' => $items->filter(fn (AssetItem $item) => isset($keep[$item->id]))->map(fn (AssetItem $item) => $shape($item) + ['why' => $keep[$item->id]])->values()->all(),
            'updated' => (int) $batch->updated_count,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function handle(AssetImportBatch $batch, User $by, ActionReason $why): array
    {
        return DB::transaction(function () use ($batch, $by, $why) {
            /** @var AssetImportBatch $locked */
            $locked = AssetImportBatch::query()->withoutGlobalScopes()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (($refusal = self::blocker($locked)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $refusal]);
            }

            $plan = $this->plan($locked);

            if ($plan['remove'] === []) {
                throw ValidationException::withMessages(['reason_text' => "Every thing of import {$locked->number} has been used since, so nothing can be taken back. Handle them one by one."]);
            }

            $removeIds = array_column($plan['remove'], 'id');

            // 1. The sheet's own hand-overs of those things, newest first.
            $movements = AssetMovement::query()->withoutGlobalScopes()
                ->where('import_batch_id', $locked->id)
                ->whereNull('reversed_at')
                ->whereHas('lines', fn ($q) => $q->whereIn('asset_item_id', $removeIds))
                ->orderByDesc('id')->get();

            foreach ($movements as $movement) {
                $this->movements->reverse($movement, $by, $why);
            }

            // 2. The things, to the bin. whyNotRemovable is asked again by recycle().
            /** @var Collection<int, AssetItem> $items */
            $items = AssetItem::query()->withoutGlobalScopes()->whereNull('deleted_at')->whereIn('id', $removeIds)->lockForUpdate()->get();

            foreach ($items->sortBy(fn (AssetItem $item) => $item->parent_id === null ? 1 : 0) as $item) {
                $item->recycle($by, new ActionReason($why->reasonId, "Import {$locked->number} reversed: {$why->text}"));
            }

            $locked->forceFill(['reversed_at' => Carbon::now(), 'reversed_by' => $by->id, 'reversal_reason' => $why->display()])->save();

            CorrectionLog::event('reversed', $by, $locked, ['reason' => $why->display(), 'number' => $locked->number, 'removed' => count($removeIds), 'kept' => count($plan['keep'])]);

            return ['removed' => count($removeIds), 'kept' => count($plan['keep'])];
        });
    }

    /**
     * @param  list<int>  $sheetMovements
     */
    private function whyKept(AssetItem $item, array $sheetMovements): ?string
    {
        // Moved by anything other than the sheet itself (a reversal of one of
        // the sheet's own hand-overs, done by hand, is the sheet's too).
        $other = AssetMovementLine::query()->where('asset_item_id', $item->id)
            ->whereNotIn('asset_movement_id', $sheetMovements)
            ->whereHas('movement', fn ($q) => $q->withoutGlobalScopes()->where(fn ($inner) => $inner
                ->whereNull('reverses_movement_id')->orWhereNotIn('reverses_movement_id', $sheetMovements ?: [0])))
            ->with('movement:id,number')
            ->first();

        if ($other !== null) {
            return "It was handed over on {$other->movement?->number}.";
        }

        // Everything else a thing can carry, with the sheet's hand-overs set aside.
        return $item->usedOtherThanByMovements();
    }
}
