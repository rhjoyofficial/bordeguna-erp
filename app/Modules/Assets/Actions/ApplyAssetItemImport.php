<?php

namespace App\Modules\Assets\Actions;

use App\Models\User;
use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Support\AssetImportPlan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a things plan, entirely or not at all (asset register bulk import,
 * 13 Oct 2026).
 *
 * ⚠⚠ Nothing here has rules of its own. Every thing is registered through
 * SaveAssetItem and every hand-over through MoveAssets, so a row that names a
 * holder leaves exactly the record a keeper's own hand-over would: custody,
 * status In use, a numbered movement the employee sees in My assets.
 *
 * ⚠ One transaction around the lot. The actions open their own inside it,
 * which Laravel nests as savepoints; the outer one decides.
 */
class ApplyAssetItemImport
{
    public const NOTE = 'Imported';

    /**
     * @return array{created:int, updated:int, issued:int, sent_out:int}
     */
    public function handle(AssetImportPlan $plan, User $by): array
    {
        if ($plan->hasErrors()) {
            throw new RuntimeException('Refusing to apply: the sheet has '.count($plan->errors).' error(s). Nothing has been written.');
        }

        return DB::transaction(function () use ($plan, $by) {
            $save = app(SaveAssetItem::class);
            $move = app(MoveAssets::class);
            $counts = ['created' => 0, 'updated' => 0, 'issued' => 0, 'sent_out' => 0];

            foreach ($plan->creates as $create) {
                $category = AssetCategory::query()->findOrFail($create['category_id']);
                $items = $save->register($category, $create['attributes'], $by, $create['copies']);
                $counts['created'] += $items->count();

                if ($create['holder'] === null && $create['party'] === null) {
                    continue;
                }

                // ⚠ A counted group with a holder: every piece goes to them. The
                // sheet describes today's state, and the pieces are with the person.
                $lines = $items->map(fn (AssetItem $item) => ['item' => $item, 'quantity' => $item->quantity])->all();

                if ($create['holder'] !== null) {
                    $move->handle(AssetMovementKind::Issue, $lines, ['employee_id' => $create['holder']['id']], $by, $create['moved_on'], self::NOTE);
                    $counts['issued'] += $items->count();
                } else {
                    $move->handle(AssetMovementKind::SendOut, $lines, ['party_id' => $create['party']->id], $by, $create['moved_on'], self::NOTE);
                    $counts['sent_out'] += $items->count();
                }
            }

            foreach ($plan->updates as $update) {
                $save->update(AssetItem::query()->findOrFail($update['item_id']), $update['attributes'], $by);
                $counts['updated']++;
            }

            return $counts;
        });
    }
}
