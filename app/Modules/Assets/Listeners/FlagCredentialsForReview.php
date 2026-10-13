<?php

namespace App\Modules\Assets\Listeners;

use App\Modules\Assets\Enums\AssetMovementKind;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetMovementLine;

/**
 * When a thing is RETURNED or passed from one person to ANOTHER, every login
 * it carries is flagged for review (review of 13 October 2026, rulings 3 and
 * 5): the person who knew the password no longer holds the thing, and the
 * one who decides whether the password must change is whoever manages the
 * logins, not the keeper filling in the hand-over.
 *
 * ⚠ Hooked on the LINE, not the movement: MoveAssets writes the movement
 * first and its lines after, so a `created` observer on the movement runs
 * before any line says which things moved. On the line, both exist, and
 * MoveAssets itself (another agent's file) is untouched.
 *
 * ⚠ Inside MoveAssets' own transaction, so a hand-over that fails flags
 * nothing.
 */
class FlagCredentialsForReview
{
    public function __invoke(AssetMovementLine $line): void
    {
        $movement = AssetMovement::query()->withoutGlobalScopes()->find($line->asset_movement_id);

        if ($movement === null || ! in_array($movement->kind, [AssetMovementKind::Return, AssetMovementKind::Reassign], true)) {
            return;
        }

        $item = AssetItem::query()->withoutGlobalScopes()->find($line->asset_item_id);

        if ($item === null) {
            return;
        }

        $flagged = AssetCredential::query()->withoutGlobalScopes()
            ->where('credentialable_type', $item->getMorphClass())
            ->where('credentialable_id', $item->id)
            ->where('needs_review', false)
            ->update(['needs_review' => true]);

        if ($flagged === 0) {
            return;
        }

        activity('assets.secrets')
            ->performedOn($item)
            ->causedBy($movement->created_by)
            ->log("{$flagged} login(s) of {$item->tag} {$item->name} flagged for review after ".mb_strtolower($movement->kind->label())." ({$movement->number})");
    }
}
