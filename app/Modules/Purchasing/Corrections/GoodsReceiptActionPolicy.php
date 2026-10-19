<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\ReceiptRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Goods receipts in the corrections process (corrections wave 1, 19 Oct
 * 2026; FINAL-PLAN 3.3 and example 2): which buttons, which blockers.
 *
 *   Fix batch details  batch number, expiry, MFG date, challan, note: the
 *                      SAME physical goods typed wrong. Store (anyone who may
 *                      receive). No money, so any month. A reason, always.
 *   Correct            reverse + the right receipt, one transaction. Two ways,
 *                      decided by what the form changes (GoodsReceiptCorrector):
 *                       - only the date (and label data): the SAME batch is
 *                         re-dated; allowed when billed and paid, and when part
 *                         is sold, unless a withdrawal would then come before
 *                         the stock existed;
 *                       - quantity or a different lot: the goods go back out
 *                         and come in again; only while untouched and unbilled.
 *   Reverse            goods untouched, nothing billed: the stock goes back
 *                      out and the order line gets its quantity back.
 *   Edit, Delete       never on a receipt.
 *
 * Who: `purchasing.correct` (Owner, Manager: the order approvers, "Store
 * requests, approver confirms"); the Tk 20,000 second person and the
 * Owner-only closed month on top, through the corrections process.
 *
 * `effectProfile()` = Ledger (GoodsReceiptLedgerCheck) + Stock
 * (GoodsReceiptStockCheck) + Allocation (bills, landed costs and supplier
 * discounts that hang off its lines: GoodsReceiptAllocationCheck).
 *
 * ⚠ A receipt's cost is the order's price. A wrong price is not corrected on
 * the receipt: the bill carries the price difference, or the receipt is
 * reversed, the order edited, and the goods received again.
 */
class GoodsReceiptActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'purchasing.goods_receipt';

    public const PERMISSION = 'purchasing.correct';

    public const FIX_PERMISSION = 'purchasing.receipt.record';

    public function __construct(
        private readonly CorrectionDatePolicy $dates,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return GoodsReceipt::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.reports.view') || $user->can(self::FIX_PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        // The goods are in stock the moment a receipt is saved.
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock, EffectKind::Allocation],
            [GoodsReceiptLedgerCheck::class, GoodsReceiptStockCheck::class, GoodsReceiptAllocationCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        // ⚠ At most two primary buttons: Fix batch details and Correct.
        // Reverse is drawn as a quieter third (FINAL-PLAN 2, screen rules).
        if ($user->can(self::FIX_PERMISSION)) {
            $actions[] = $this->availability($document, $user, DocumentAction::FixBatchDetails);
        }

        if ($user->can(self::PERMISSION)) {
            $actions[] = $this->availability($document, $user, DocumentAction::Correct);
            $actions[] = $this->availability($document, $user, DocumentAction::Reverse);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var GoodsReceipt $document */
        if ($action === DocumentAction::FixBatchDetails) {
            return $this->fixBatchDetails($document, $user);
        }

        $offered = in_array($action, [DocumentAction::Reverse, DocumentAction::Correct], true);
        $blockers = [];

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a goods receipt.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if ($document->isReversed()) {
            $blockers[] = $this->alreadyDone($document);
        }

        $waiting = CorrectionRequest::query()
            ->where('pending_key', $document->getMorphClass().':'.$document->getKey())
            ->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$this->numberOf($document)} is already waiting for approval.");
        }

        if (! $document->isReversed()) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $dates = $this->dates->resolve($this->originalDate($document), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $above = $offered && $this->secondPerson->applies((int) $document->business_id, $this->valueMinor($document));

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: $action === DocumentAction::Correct,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Correct ? 'correction' : 'reversal',
            approval: $this->secondPerson->route($closedNotOwner || $dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    public function numberOf(Model $document): string
    {
        return "Receipt #{$document->getKey()}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.receipts.show', $document->getKey());
    }

    /** The receipt's own business date (the day the goods arrived, as entered). */
    public function originalDate(GoodsReceipt $receipt): Carbon
    {
        return Carbon::parse($receipt->received_at ?? $receipt->created_at)->startOfDay();
    }

    /** What the receipt is worth on the order's terms, in paisa (the GRNI credit). */
    public function valueMinor(GoodsReceipt $receipt): int
    {
        $receipt->loadMissing('lines.purchaseOrderLine');

        return (new ReceiptRegister)->valueOf($receipt);
    }

    private function fixBatchDetails(GoodsReceipt $receipt, User $user): ActionAvailability
    {
        $blockers = [];

        if (! $user->can(self::FIX_PERMISSION)) {
            $blockers[] = Blocker::noPermission(DocumentAction::FixBatchDetails);
        }

        if ($receipt->isReversed()) {
            $blockers[] = $this->alreadyDone($receipt);
        }

        return new ActionAvailability(
            action: DocumentAction::FixBatchDetails,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::FIX_PERMISSION,
            primary: true,
            requiresReason: true,
            reasonContext: 'fix_details',
            approval: ApprovalRoute::None,
        );
    }

    private function alreadyDone(GoodsReceipt $receipt): Blocker
    {
        $receipt->loadMissing('reversedBy:id,name');
        $on = $receipt->reversed_at?->format('j M') ?? 'an earlier day';
        $by = $receipt->reversedBy?->name ?? 'somebody';
        $successor = CorrectionLink::query()
            ->where('original_type', $receipt->getMorphClass())
            ->where('original_id', $receipt->getKey())
            ->value('successor_number');

        return $successor === null
            ? new Blocker('already_reversed', "{$this->numberOf($receipt)} was already reversed on {$on} by {$by}.")
            : new Blocker('already_corrected', "{$this->numberOf($receipt)} was already corrected on {$on} by {$by}. Correct {$successor} instead.");
    }
}
