<?php

namespace App\Modules\Crm\Corrections;

use App\Models\User;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\Lead;
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
 * ⭐ A call, visit, meeting, message or note in the corrections process
 * (corrections wave 3, 24 Oct 2026; FINAL-PLAN 3.2 "CRM activity: author
 * Edit or Delete within 24 hours. After that Correct (old one struck
 * through, kept)"):
 *
 *   within 24 hours of being typed   Edit (no reason), Delete (the bin)
 *   after that                       Correct: a new entry replaces it, the
 *                                    old one stays, struck through
 *   corrected                        nothing (correct the newer one)
 *
 * The stage is worked out, never picked: inside its 24 hours an entry is
 * still the author's draft of what happened (so Edit is not offered on a
 * Done record, PolicyKit rule 1); after them it is the record.
 *
 * ⚠ Only the AUTHOR changes an entry, whatever their role: it is their account
 * of what they did. Everybody else adds a new entry. The author still needs
 * crm.leads.manage (the right that records one).
 *
 * ⚠ What may change is the account, never the proof: the type, the location
 * and the photos of a visit stay as they were recorded (EditCrmActivity).
 */
class CrmActivityActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'crm.activity';

    public const PERMISSION = 'crm.leads.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CrmActivity::class;
    }

    public function canView(Model $document, User $user): bool
    {
        // ⚠ The model's own scope already hides an entry on a lead this person
        // may not see; this is the screen's right on top of it.
        return $user->can('crm.leads.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var CrmActivity $document */
        return ! $document->isCorrected() && $document->isFresh() ? DocumentStage::Draft : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var CrmActivity $document */
        $stage = $this->stageOf($document);

        if ($document->isCorrected() || ! $user->can(self::PERMISSION)) {
            return new ActionSet($stage, []);
        }

        $verbs = $stage === DocumentStage::Draft ? [DocumentAction::Edit, DocumentAction::Delete] : [DocumentAction::Correct];

        return new ActionSet($stage, array_map(fn (DocumentAction $verb) => $this->availability($document, $user, $verb), $verbs));
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var CrmActivity $document */
        $fresh = $document->isFresh();
        $blockers = [];

        if (! in_array($action, [DocumentAction::Edit, DocumentAction::Delete, DocumentAction::Correct], true)) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a call, visit or note.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($blockers === [] && (int) $document->recorded_by !== (int) $user->id) {
            $author = $document->recordedBy?->name ?? 'the person who recorded it';
            $blockers[] = new Blocker('not_author', "Only {$author}, who recorded it, can change it. Add a new entry instead.");
        }

        if ($blockers === [] && $document->isCorrected()) {
            $blockers[] = new Blocker('already_corrected', 'This entry was already corrected. Correct the newer entry instead.');
        }

        if ($blockers === [] && in_array($action, [DocumentAction::Edit, DocumentAction::Delete], true) && ! $fresh) {
            $blockers[] = new Blocker('older_than_a_day', 'More than 24 hours have passed since it was recorded. Correct it instead.');
        }

        if ($blockers === [] && $action === DocumentAction::Correct && $fresh) {
            $blockers[] = new Blocker('still_fresh', 'It was recorded less than 24 hours ago. Edit it instead.');
        }

        // ⚠ A correction stands for the entry it replaced; binning it would
        // leave that one struck through with nothing in its place.
        if ($blockers === [] && $action === DocumentAction::Delete && $document->correction_of_id !== null) {
            $blockers[] = new Blocker('is_correction', 'This entry corrects an earlier one. Edit it instead.');
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            // Editing your own fresh entry is editing a draft: no reason.
            requiresReason: $action !== DocumentAction::Edit,
            reasonContext: match ($action) {
                DocumentAction::Delete => 'deletion',
                DocumentAction::Edit => null,
                default => 'correction',
            },
            approval: ApprovalRoute::None,
        );
    }

    /** "Call of 24/10/2026". */
    public function numberOf(Model $document): string
    {
        /** @var CrmActivity $document */
        return $document->type->label().' of '.($document->occurred_at?->format('d/m/Y') ?? '?');
    }

    public function urlOf(Model $document): ?string
    {
        /** @var CrmActivity $document */
        $kind = $document->subject_type === (new Lead)->getMorphClass() || $document->subject_type === Lead::class ? 'lead' : 'customer';

        return route('crm.timeline', ['type' => $kind, 'id' => $document->subject_id]);
    }
}
