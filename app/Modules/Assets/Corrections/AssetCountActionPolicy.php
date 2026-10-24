<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): an asset count (FINAL-PLAN 3.5,
 * "Asset count, open: Cancel with a reason. No adjustment posted from it").
 *
 *   open        Cancel: the place is free for the real count; its marks stay
 *               on the cancelled count; nothing about any thing changes
 *   closed      nothing: its gaps are settled one by one, as before
 *   cancelled   nothing
 *
 * No effects: an open count changes no thing until it is closed.
 */
class AssetCountActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'assets.count';

    public const PERMISSION = 'assets.items.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return AssetCount::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('assets.items.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var AssetCount $document */
        return $document->isOpen() ? DocumentStage::ApprovedNoEffect : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var AssetCount $document */
        $actions = $document->isOpen() && $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Cancel)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var AssetCount $document */
        $blockers = match (true) {
            $action !== DocumentAction::Cancel => [AssetsPolicySupport::notOffered($action, 'an asset count')],
            $document->isCancelled() => ["{$document->number} was already cancelled."],
            ! $document->isOpen() => ["{$document->number} is closed. Settle its gaps instead."],
            default => [],
        };

        return AssetsPolicySupport::plain($action, $user, self::PERMISSION, $blockers, 'cancellation');
    }

    public function numberOf(Model $document): string
    {
        /** @var AssetCount $document */
        return $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('assets.counts.show', $document->getKey());
    }
}
