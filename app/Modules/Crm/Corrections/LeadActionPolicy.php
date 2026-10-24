<?php

namespace App\Modules\Crm\Corrections;

use App\Models\User;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\LeadFormOptions;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Crm\Support\PartyUsage;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A lead in the corrections process (corrections wave 3, 24 Oct 2026;
 * FINAL-PLAN 3.2 "Lead" and 6.3):
 *
 *   working lead      list data      Delete (the Recycle bin) when it was never
 *                                    worked; otherwise it is archived
 *   converted lead    done           Reverse (the conversion), while the
 *                                    customer or supplier has nothing recorded
 *   lost lead                        Reopen, with a reason (ReopenLead; the
 *                                    contract has no Reopen verb, so it is
 *                                    drawn beside these, see reopenBlocker)
 *
 *   Delete    crm.leads.verify (the ASM's, like Archive: an officer must not be
 *             able to make a colleague's shop disappear)
 *   Reverse   crm.leads.convert, and for a supplier lead the supplier
 *             managers' right too (the same pair that converts it)
 *   Reopen    crm.leads.manage (whoever works the lead; it was already able
 *             to change the status, now with a reason and a History line)
 *
 * ⚠ The lead's own visibility rule (own and team) holds first: canView asks
 * LeadVisibility, and every route binds the scoped model.
 *
 * ⚠ A lead moves no money and no stock: the effect profile is empty and the
 * corrections service never runs on it (CrmRecordCorrector refuses).
 */
class LeadActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'crm.lead';

    public const DELETER = 'crm.leads.verify';

    public const REOPENER = 'crm.leads.manage';

    public function __construct(private readonly PartyUsage $usage) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Lead::class;
    }

    public function canView(Model $document, User $user): bool
    {
        /** @var Lead $document */
        return $user->can('crm.leads.view') && app(LeadVisibility::class)->canSee($user, $document);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var Lead $document */
        return $this->isConverted($document) ? DocumentStage::Done : DocumentStage::ListData;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var Lead $document */
        $verb = $this->isConverted($document) ? DocumentAction::Reverse : DocumentAction::Delete;
        $availability = $this->availability($document, $user, $verb);

        // Somebody without the right sees no button for it at all.
        return new ActionSet($this->stageOf($document), $this->mayAct($document, $user, $verb) ? [$availability] : []);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var Lead $document */
        $converted = $this->isConverted($document);
        $permission = $action === DocumentAction::Reverse ? $this->reverser($document) : self::DELETER;
        $blockers = [];

        $offered = match ($action) {
            DocumentAction::Delete => ! $converted,
            DocumentAction::Reverse => $converted,
            default => false,
        };

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', match ($action) {
                DocumentAction::Reverse => 'This lead was not converted, so there is no conversion to reverse.',
                DocumentAction::Delete => 'This lead was converted. Reverse the conversion first.',
                default => "{$action->label()} is not offered on a lead.",
            });
        }

        if (! $this->mayAct($document, $user, $action)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($blockers === [] && $action === DocumentAction::Delete && ($why = $document->whyNotRemovable()) !== null) {
            $blockers[] = new Blocker('used', $why);
        }

        if ($blockers === [] && $action === DocumentAction::Reverse && ($why = $this->partyInUse($document)) !== null) {
            $blockers[] = new Blocker('party_used', $why);
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $permission,
            primary: true,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Delete ? 'deletion' : 'reversal',
            approval: ApprovalRoute::None,
        );
    }

    /**
     * Reopen is not a contract verb (DocumentAction has none), so the lead's
     * page asks this for its Reopen button. Null when it may be reopened.
     */
    public function reopenBlocker(Lead $lead, User $user): ?string
    {
        return match (true) {
            ! $user->can(self::REOPENER) => 'You are not allowed to reopen this lead.',
            $lead->status !== LeadStatus::Lost => 'This lead is not marked lost, so there is nothing to reopen.',
            $lead->isArchived() => 'This lead is archived. Restore it from the archive first.',
            default => null,
        };
    }

    public function numberOf(Model $document): string
    {
        /** @var Lead $document */
        return filled($document->name) ? (string) $document->name : "Lead #{$document->id}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('crm.timeline', ['type' => 'lead', 'id' => $document->getKey()]);
    }

    public function isConverted(Lead $lead): bool
    {
        return $lead->converted_customer_id !== null || $lead->converted_supplier_id !== null;
    }

    /** The permission that reverses this lead's conversion. */
    public function reverser(Lead $lead): string
    {
        return $lead->lead_type === LeadType::Supplier || $lead->converted_supplier_id !== null
            ? 'purchasing.suppliers.manage'
            : 'crm.leads.convert';
    }

    /**
     * ⚠ FINAL-PLAN 3.2: "Reverse conversion if the new customer is unused".
     * Applied to a customer that EXISTED before too: an existing customer may
     * have been picked by mistake, but once anything was recorded against it
     * after the link, the link is part of that record's story.
     */
    private function partyInUse(Lead $lead): ?string
    {
        if ($lead->converted_customer_id !== null) {
            $customer = Customer::query()->withoutGlobalScopes()->find($lead->converted_customer_id);
            $where = $this->usage->usedIn('customer_id', (int) $lead->converted_customer_id);

            return $where === [] ? null
                : 'The customer '.($customer?->name ?? '').' already appears in '.implode(', ', $where).', so the conversion cannot be reversed.';
        }

        $supplier = Supplier::query()->withoutGlobalScopes()->find($lead->converted_supplier_id);
        $where = $this->usage->usedIn('supplier_id', (int) $lead->converted_supplier_id);

        return $where === [] ? null
            : 'The supplier '.($supplier?->name ?? '').' already appears in '.implode(', ', $where).', so the conversion cannot be reversed.';
    }

    /**
     * The same right that converts it: a supplier lead needs the supplier
     * managers' right as well (LeadFormOptions::mayConvertSuppliers).
     */
    private function mayAct(Lead $lead, User $user, DocumentAction $action): bool
    {
        return match (true) {
            $action !== DocumentAction::Reverse => $user->can(self::DELETER),
            $this->reverser($lead) === 'purchasing.suppliers.manage' => LeadFormOptions::mayConvertSuppliers($user),
            default => $user->can('crm.leads.convert'),
        };
    }
}
