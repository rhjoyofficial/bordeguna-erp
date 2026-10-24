<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Assets\Actions\DisposeAssetItem;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a thing in the register
 * (FINAL-PLAN 3.5 and section 2, "Lists" and "Leaving the company").
 *
 *   kept, never used   Delete (to the bin, tag reserved) and Dispose
 *   kept, used         Delete greyed with its sentence, and Dispose
 *   disposed           Undo disposal (30 days, nothing since)
 *
 * ⚠ No effects of its own: a thing is list data. Custody lives on its
 * hand-overs (AssetMovementActionPolicy), so Delete is a Removable question
 * (`whyNotRemovable`), never a reversal. Dispose posts no journal.
 *
 *   Delete           assets.items.manage (Custodian, Keeper, Manager, Owner)
 *   Dispose, Undo    assets.items.dispose; above the approval limit, the
 *                    Owner or a Manager only (DisposeAssetItem)
 */
class AssetItemActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'assets.thing';

    public function __construct(private readonly DisposeAssetItem $dispose) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return AssetItem::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('assets.items.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var AssetItem $document */
        return $document->status === AssetStatus::Disposed ? DocumentStage::LeftCompany : DocumentStage::ListData;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var AssetItem $document */
        $stage = $this->stageOf($document);

        if (! $user->can('assets.items.manage') && ! $user->can('assets.items.dispose')) {
            return new ActionSet($stage, []);
        }

        $actions = $stage === DocumentStage::LeftCompany
            ? [$this->availability($document, $user, DocumentAction::UndoDisposal)]
            : [$this->availability($document, $user, DocumentAction::Delete), $this->availability($document, $user, DocumentAction::Dispose)];

        return new ActionSet($stage, $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var AssetItem $document */
        return match ($action) {
            DocumentAction::Delete => AssetsPolicySupport::plain($action, $user, 'assets.items.manage', array_filter([$document->whyNotRemovable()]), 'deletion'),
            DocumentAction::Dispose => AssetsPolicySupport::plain($action, $user, 'assets.items.dispose', array_filter([$this->dispose->blocker($document, $user)]), 'disposal'),
            DocumentAction::UndoDisposal => AssetsPolicySupport::plain($action, $user, 'assets.items.dispose', array_filter([$this->dispose->undoBlocker($document)]), 'restore', verb: 'undo the disposal of'),
            default => AssetsPolicySupport::plain($action, $user, 'assets.items.manage', [AssetsPolicySupport::notOffered($action, 'a thing')], 'correction'),
        };
    }

    public function numberOf(Model $document): string
    {
        /** @var AssetItem $document */
        return $document->tag;
    }

    public function urlOf(Model $document): ?string
    {
        return $document->trashed() ? null : route('assets.items.show', $document->getKey());
    }
}
