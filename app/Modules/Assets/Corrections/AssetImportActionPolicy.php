<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Assets\Actions\ReverseAssetImport;
use App\Modules\Assets\Models\AssetImportBatch;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a things sheet that was applied
 * (FINAL-PLAN 3.5, "Thing import batch: Reverse import, within 7 days,
 * untouched things only").
 *
 *   applied, within 7 days   Reverse import (ReverseAssetImport: the sheet's
 *                            hand-overs reversed, its untouched things to
 *                            the bin, the used ones kept and listed)
 *   reversed, older          nothing
 *
 * Effect: Custody (a sheet row may name a holder), covered by
 * ImportUntouchedCheck.
 */
class AssetImportActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'assets.thing_import';

    public const PERMISSION = 'assets.items.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return AssetImportBatch::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('assets.items.manage');
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Custody], [ImportUntouchedCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var AssetImportBatch $document */
        $actions = $user->can(self::PERMISSION) && ! $document->isReversed()
            ? [$this->availability($document, $user, DocumentAction::Reverse)]
            : [];

        return new ActionSet(DocumentStage::Done, $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var AssetImportBatch $document */
        if ($action !== DocumentAction::Reverse) {
            return AssetsPolicySupport::plain($action, $user, self::PERMISSION, [AssetsPolicySupport::notOffered($action, 'an import')], 'reversal');
        }

        $blockers = array_filter([ReverseAssetImport::blocker($document)]);

        if ($blockers === []) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        return AssetsPolicySupport::plain($action, $user, self::PERMISSION, array_values($blockers), 'reversal', verb: 'reverse');
    }

    public function numberOf(Model $document): string
    {
        /** @var AssetImportBatch $document */
        return "Import {$document->number}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('assets.items.import');
    }
}
