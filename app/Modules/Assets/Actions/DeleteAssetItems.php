<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Corrections\ActionReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): things nobody ever used go to the
 * Recycle bin, one or many at once (FINAL-PLAN 3.5, "Thing (asset), never
 * used: Delete to the bin, also in bulk. The tag is never reused").
 *
 * ⚠ All or nothing (FINAL-PLAN 6.6, item 2): if one thing on the list was
 * used, nothing moves and the sentence says which and why. A half-deleted
 * double import is worse than none.
 *
 * ⚠ The tag stays reserved: the row keeps it, and SaveAssetItem numbers new
 * tags past trashed rows. Restore is the Owner's, from the bin.
 */
final class DeleteAssetItems
{
    public const MAX = 200;

    /**
     * @param  list<int>  $ids
     *
     * @throws ValidationException
     */
    public function handle(array $ids, User $by, ActionReason $why): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === [] || count($ids) > self::MAX) {
            throw ValidationException::withMessages(['ids' => 'Choose between 1 and '.self::MAX.' things to delete.']);
        }

        return DB::transaction(function () use ($ids, $by, $why) {
            // Business scope on: a thing of another business is simply not found.
            $items = AssetItem::query()->whereIn('id', $ids)->lockForUpdate()->get();

            if ($items->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'One of the things is no longer in the register.']);
            }

            // Parts before the thing they belong to, so a thing and its parts
            // can go in one step; each is asked again right before it goes,
            // and the first refusal rolls the whole list back.
            foreach ($items->sortBy(fn (AssetItem $item) => $item->parent_id === null ? 1 : 0) as $item) {
                if (($refusal = $item->whyNotRemovable()) !== null) {
                    throw ValidationException::withMessages(['reason_text' => $items->count() > 1 ? "Nothing was deleted. {$refusal}" : $refusal]);
                }

                $item->recycle($by, $why);
            }

            return $items->count();
        });
    }
}
