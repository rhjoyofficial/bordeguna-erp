<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\MaintenanceState;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): a maintenance record (FINAL-PLAN
 * 3.5, "Maintenance, done: Reopen. Within 30 days").
 *
 *   open        Cancel, as before (its own form, slice A4)
 *   done        Reopen: back to open, then completed again correctly or
 *               cancelled; within 30 days, while its thing is still kept
 *   cancelled   nothing
 *
 * ⚠⚠ CONTRACT GAP: DocumentAction has no "Reopen" case (the enum is frozen,
 * PHASE-0-CONTRACT 1.1). Reopen is carried as DocumentAction::Reverse (it
 * reverses the completion) and the screen says "Reopen". Reported to the
 * caller instead of changing the shared enum.
 *
 * No effects of its own: no money, no stock, no custody (the cost and the
 * voucher stay as they were and are changed on their own form).
 */
class AssetMaintenanceActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'assets.maintenance';

    public const PERMISSION = 'assets.items.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return AssetMaintenanceRecord::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('assets.items.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var AssetMaintenanceRecord $document */
        return $document->state === MaintenanceState::Open ? DocumentStage::ApprovedNoEffect : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var AssetMaintenanceRecord $document */
        $actions = $document->state === MaintenanceState::Done && $user->can(self::PERMISSION)
            ? [$this->availability($document, $user, DocumentAction::Reverse)]
            : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var AssetMaintenanceRecord $document */
        $blockers = $action === DocumentAction::Reverse
            ? array_filter([self::reopenBlocker($document)])
            : [AssetsPolicySupport::notOffered($action, 'a maintenance record')];

        return AssetsPolicySupport::plain($action, $user, self::PERMISSION, $blockers, 'reversal', verb: 'reopen');
    }

    /** Why a record cannot be reopened now, or null. Asked again by the action under its lock. */
    public static function reopenBlocker(AssetMaintenanceRecord $record): ?string
    {
        if ($record->state !== MaintenanceState::Done) {
            return "{$record->number} is not done, so there is nothing to reopen.";
        }

        if ($record->closed_at === null || $record->closed_at->copy()->addDays(AssetMaintenanceRecord::REOPEN_DAYS)->isPast()) {
            return "{$record->number} was done more than ".AssetMaintenanceRecord::REOPEN_DAYS.' days ago. Record new maintenance instead.';
        }

        $item = AssetItem::query()->withoutGlobalScopes()->find($record->asset_item_id);

        if ($item === null || $item->trashed() || $item->status === AssetStatus::Disposed) {
            return "The thing of {$record->number} is no longer kept.";
        }

        if ($record->expense_voucher_id !== null) {
            return "{$record->number} is linked to an expense voucher. Remove the voucher link first.";
        }

        return null;
    }

    public function numberOf(Model $document): string
    {
        /** @var AssetMaintenanceRecord $document */
        return $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('assets.maintenance.show', $document->getKey());
    }
}
