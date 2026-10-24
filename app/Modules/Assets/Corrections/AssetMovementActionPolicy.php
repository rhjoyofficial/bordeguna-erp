<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a hand-over (FINAL-PLAN 3.5).
 *
 *   on its way (transfer)   Cancel: the things never left their place
 *   done                    Reverse: a linked mirror movement on the same
 *                           day, while it is still the latest movement of
 *                           every thing on it and within 7 days
 *   a reversal, reversed, cancelled   nothing
 *
 * Effect: Custody, covered by LatestHandOverCheck. Who: the Custodian
 * (`assets.items.manage`, the permission that records hand-overs).
 *
 * ⚠ "Correct date while it is the latest" (FINAL-PLAN 3.5) is not built:
 * Reverse and record it again on the right day does the same, and keeps
 * both in the history.
 */
class AssetMovementActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'assets.hand_over';

    public const PERMISSION = 'assets.items.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return AssetMovement::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('assets.items.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var AssetMovement $document */
        return $document->status === AssetMovementStatus::Dispatched ? DocumentStage::ApprovedNoEffect : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Custody], [LatestHandOverCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var AssetMovement $document */
        if (! $user->can(self::PERMISSION) || $document->isReversal() || $document->isReversed()) {
            return new ActionSet($this->stageOf($document), []);
        }

        $action = $document->status === AssetMovementStatus::Dispatched ? DocumentAction::Cancel : DocumentAction::Reverse;

        return new ActionSet($this->stageOf($document), [$this->availability($document, $user, $action)]);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var AssetMovement $document */
        if (! in_array($action, [DocumentAction::Cancel, DocumentAction::Reverse], true)) {
            return AssetsPolicySupport::plain($action, $user, self::PERMISSION, [AssetsPolicySupport::notOffered($action, 'a hand-over')], 'reversal');
        }

        $blockers = [];

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        foreach ($this->effectProfile()->checks() as $check) {
            if (($blocker = $check->blocker($document, $action)) !== null) {
                $blockers[] = $blocker;
            }
        }

        return AssetsPolicySupport::plain($action, $user, self::PERMISSION, $blockers, $action === DocumentAction::Cancel ? 'cancellation' : 'reversal');
    }

    public function numberOf(Model $document): string
    {
        /** @var AssetMovement $document */
        return $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('assets.movements.show', $document->getKey());
    }
}
