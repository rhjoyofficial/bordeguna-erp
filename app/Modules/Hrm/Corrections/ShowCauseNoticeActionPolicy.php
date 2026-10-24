<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Hrm\Models\ShowCauseNotice;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A show cause notice in the corrections process (corrections wave 3, 24 Oct
 * 2026; FINAL-PLAN 3.4): issued, it can be Withdrawn by HR with a date and a
 * reason. Nothing else: its wording never changes, and a withdrawn one stays
 * on record, marked Withdrawn. It moves no money, stock or pay.
 */
class ShowCauseNoticeActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.show_cause_notice';

    public const PERMISSION = 'hrm.attendance.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return ShowCauseNotice::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var ShowCauseNotice $document */
        if ($document->isWithdrawn() || ! $user->can(self::PERMISSION)) {
            return new ActionSet(DocumentStage::Done, []);
        }

        return new ActionSet(DocumentStage::Done, [$this->availability($document, $user, DocumentAction::Withdraw)]);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var ShowCauseNotice $document */
        $blockers = [];

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($action !== DocumentAction::Withdraw) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a show cause notice.");
        } elseif ($document->isWithdrawn()) {
            $blockers[] = new Blocker('withdrawn', $this->numberOf($document).' is already withdrawn.');
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            requiresReason: true,
            reasonContext: 'withdrawal',
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var ShowCauseNotice $document */
        $name = $document->employee?->fullName();

        return 'Notice #'.$document->getKey().($name ? " to {$name}" : '');
    }

    public function urlOf(Model $document): ?string
    {
        return route('hrm.notices.show', $document->getKey());
    }
}
