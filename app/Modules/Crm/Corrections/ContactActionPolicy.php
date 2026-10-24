<?php

namespace App\Modules\Crm\Corrections;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A phone book contact in the corrections process (corrections wave 3,
 * 24 Oct 2026; FINAL-PLAN 2 "Lists: Delete if never used, otherwise
 * Deactivate" and 6.3):
 *
 *   never sent a message   Delete (the Recycle bin; the Owner restores)
 *   sent a message         Deactivate / Reactivate (the phone book's own
 *                          Switch off and Switch on, unchanged)
 *
 * The screen shows Delete or Switch off, never both (FINAL-PLAN 6.4).
 *
 * ⚠ A contact pulled from a lead follows the lead's visibility (Contact::
 * scopeVisibleLeadsOnly): somebody who may not see the lead may not delete
 * its shadow either.
 */
class ContactActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'crm.contact';

    public const PERMISSION = 'crm.contacts.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Contact::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('crm.contacts.view')
            && Contact::query()->visibleLeadsOnly()->whereKey($document->getKey())->exists();
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::ListData;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var Contact $document */
        if (! $user->can(self::PERMISSION)) {
            return new ActionSet(DocumentStage::ListData, []);
        }

        $verb = match (true) {
            $document->whyNotRemovable() === null => DocumentAction::Delete,
            $document->is_active => DocumentAction::Deactivate,
            default => DocumentAction::Reactivate,
        };

        return new ActionSet(DocumentStage::ListData, [$this->availability($document, $user, $verb)]);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var Contact $document */
        $blockers = [];

        if (! in_array($action, [DocumentAction::Delete, DocumentAction::Deactivate, DocumentAction::Reactivate], true)) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a phone book contact.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($blockers === [] && $action === DocumentAction::Delete && ($why = $document->whyNotRemovable()) !== null) {
            $blockers[] = new Blocker('used', $why);
        }

        if ($blockers === [] && $action === DocumentAction::Deactivate && ! $document->is_active) {
            $blockers[] = new Blocker('already_off', 'This contact is already switched off.');
        }

        if ($blockers === [] && $action === DocumentAction::Reactivate && $document->is_active) {
            $blockers[] = new Blocker('already_on', 'This contact is already switched on.');
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            // ⚠ Switch off and on keep the phone book's one-click routes (they
            // only decide who a future send reaches); Delete asks why.
            requiresReason: $action === DocumentAction::Delete,
            reasonContext: $action === DocumentAction::Delete ? 'deletion' : null,
            approval: ApprovalRoute::None,
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var Contact $document */
        return (string) $document->name;
    }

    public function urlOf(Model $document): ?string
    {
        return route('crm.contacts.index', ['search' => $document->getAttribute('phone') ?? $document->getAttribute('name')]);
    }
}
