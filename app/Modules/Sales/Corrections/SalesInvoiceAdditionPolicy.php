<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DateRuleSummary;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A price credit and an extra charge on an issued sales invoice, through the
 * corrections engine (corrections wave 1 gaps, 24 Oct 2026; FINAL-PLAN 3.2,
 * 4.2). Until today both were plain form posts: no preview, no Tk 20,000
 * second person, no closed-month rule.
 *
 * Neither changes the invoice (FINAL-PLAN 6.6 rule 1). Each ISSUES a new
 * document against it, so on the engine they are a Correct whose "successor"
 * is that document, and the original stays live (LeavesOriginalLive):
 *
 *   sales.price_credit   a price credit note (goods kept, the customer pays
 *                        less): no more than the invoice still owes
 *   sales.extra_charge   a supplementary invoice (the customer owes more)
 *
 * The date is the one typed (SalesInvoiceAdditionCorrector::correctionDates):
 * an open day is anyone's with `sales.correct`; a day in a closed month is the
 * Owner's one-document exception. The Tk 20,000 rule reads the new document's
 * amount, judged on the form at the preview.
 *
 * Who: Owner, Manager, Accountant (`sales.correct`), as the old screens.
 */
abstract class SalesInvoiceAdditionPolicy implements DocumentActionPolicy
{
    public const PERMISSION = 'sales.correct';

    /** "Price credit" / "Extra charge": the button word and the dialog title. */
    abstract public function label(): string;

    /** @return list<Blocker> this kind's own refusals */
    abstract protected function ownBlockers(SalesInvoice $invoice): array;

    public function modelClass(): string
    {
        return SalesInvoice::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('sales.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger], [SalesLedgerCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        // ⚠ Somebody without the permission sees no button at all.
        return new ActionSet(
            $this->stageOf($document),
            $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Correct)] : [],
        );
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var SalesInvoice $document */
        $blockers = [];
        $number = $this->numberOf($document);

        if ($action !== DocumentAction::Correct) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered here.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_credited', "{$number} is credited in full, so nothing more can be issued against it.");
        } elseif ((bool) $document->is_opening) {
            $blockers[] = new Blocker('opening', "{$number} is an opening balance; correct it under Accounting, Opening balances.");
        } elseif ((bool) $document->is_staff_sale) {
            $blockers[] = new Blocker('staff_sale', "{$number} is a staff purchase, which Staff purchases corrects.");
        } elseif (SalesInvoiceActionPolicy::isCounterSale($document)) {
            $blockers[] = new Blocker('counter_sale', "{$number} is a counter sale: void it and ring it up again.");
        } else {
            array_push($blockers, ...$this->ownBlockers($document));

            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $waiting = CorrectionRequest::query()->where('pending_key', CorrectionLink::liveKeyFor($document))->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$number} is already waiting for approval.");
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: false,
            requiresReason: true,
            reasonContext: 'correction',
            // The amount and the date are on the form: the preview decides.
            approval: ApprovalRoute::None,
            date: new DateRuleSummary('as_dated', 'Dated the day you choose. A day in a closed month is the Owner\'s.'),
        );
    }

    /** The invoice's number; for the credit note a price credit issues, its own CN number. */
    public function numberOf(Model $document): string
    {
        return (string) ($document instanceof SalesCreditNote ? $document->credit_note_number : $document->invoice_number);
    }

    public function urlOf(Model $document): ?string
    {
        return route('sales.invoices.show', $document);
    }
}
