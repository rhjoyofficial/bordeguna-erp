<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Actions\PostInventoryAdjustmentJournal;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a stock adjustment ("Record damage
 * or loss", found stock, a count variance), FINAL-PLAN 3.3.
 *
 *   Waiting    Edit, Withdraw          the raiser only; a reason for each
 *   Withdrawn  Edit (and send again)   the raiser only; no reason (a draft)
 *   Approved   Reverse                 the stock approver; back into the SAME
 *                                      batches on the adjustment's own date
 *   Rejected   nothing                 it never moved anything
 *
 * Correct is not offered: a wrong approved correction is reversed and raised
 * again, so the new one meets the approval limit like the first. A cost change
 * (revaluation) is never reversed here: it is put right with another one.
 *
 * `effectProfile()` = Ledger (AdjustmentLedgerCheck) + Stock (AdjustmentStockCheck).
 */
class InventoryAdjustmentActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.stock_adjustment';

    /** The stock approver (FINAL-PLAN 3.3 "Who"). */
    public const REVERSE_PERMISSION = 'inventory.stock.approve';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return InventoryAdjustment::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('inventory.reports.view') || $user->can('inventory.stock.adjust')
            || $user->can('inventory.stock.request') || $user->can('inventory.stock.approve');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var InventoryAdjustment $document */
        return match ($document->approvalRequest?->status) {
            ApprovalStatus::Pending => DocumentStage::Waiting,
            ApprovalStatus::Returned => DocumentStage::Draft,
            ApprovalStatus::Approved => DocumentStage::Done,
            // Rejected: decided, nothing ever moved.
            default => DocumentStage::ApprovedNoEffect,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock],
            [AdjustmentLedgerCheck::class, AdjustmentStockCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $stage = $this->stageOf($document);
        $actions = match ($stage) {
            DocumentStage::Waiting => [
                $this->availability($document, $user, DocumentAction::Edit),
                $this->availability($document, $user, DocumentAction::Withdraw),
            ],
            DocumentStage::Draft => [$this->availability($document, $user, DocumentAction::Edit)],
            // ⚠ Somebody who may not approve stock sees no Reverse at all.
            DocumentStage::Done => $user->can(self::REVERSE_PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [],
            default => [],
        };

        return new ActionSet($stage, $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var InventoryAdjustment $document */
        $stage = $this->stageOf($document);
        $number = $this->numberOf($document);
        $raiser = (int) $document->created_by === (int) $user->id;
        $raisePermission = $user->can('inventory.stock.adjust') ? 'inventory.stock.adjust' : 'inventory.stock.request';

        if ($action === DocumentAction::Edit || $action === DocumentAction::Withdraw) {
            $blockers = [];
            $open = $action === DocumentAction::Edit ? [DocumentStage::Waiting, DocumentStage::Draft] : [DocumentStage::Waiting];

            if (! in_array($stage, $open, true)) {
                $blockers[] = new Blocker('not_waiting', "{$number} is no longer waiting for approval.");
            } elseif (! $raiser) {
                $blockers[] = new Blocker('not_raiser', 'Only '.($document->createdBy?->name ?? 'the person').' who raised it can '.strtolower($action->label()).' it.');
            }

            if ($document->changesValueOnly()) {
                $blockers[] = new Blocker('cost_change', 'A cost change cannot be edited or withdrawn here. Ask an approver to reject it.');
            }

            return StockPolicySupport::plain(
                $action,
                $user,
                $raisePermission,
                $blockers,
                requiresReason: $stage === DocumentStage::Waiting,
                reasonContext: $action === DocumentAction::Withdraw ? 'withdrawal' : 'correction',
            );
        }

        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::REVERSE_PERMISSION, [StockPolicySupport::notOffered($action, 'a stock correction')], true, null, primary: false);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $document->loadMissing('reversedBy:id,name');
            $blockers[] = new Blocker('already_reversed', "{$number} was already reversed on ".($document->reversed_at?->format('j M') ?? 'an earlier day').' by '.($document->reversedBy?->name ?? 'somebody').'.');
        } elseif ($stage !== DocumentStage::Done) {
            $blockers[] = new Blocker('not_posted', "{$number} was never approved, so there is nothing to reverse.");
        } elseif ($document->changesValueOnly()) {
            $blockers[] = new Blocker('cost_change', 'A cost change is not reversed: record a new cost change instead.');
        } elseif ($this->support->closedOn($this->originalDate($document)) && app(PostInventoryAdjustmentJournal::class)->existingJournal($document) === null) {
            // The closed-month pass is granted for exactly one journal.
            $blockers[] = new Blocker('closed_no_journal', "{$number} posted nothing to the ledger, so its closed month cannot take a reversal. Ask the administrator.");
        }

        return $this->support->correction(
            $document,
            $user,
            $action,
            $blockers,
            $this->effectProfile(),
            $this->originalDate($document),
            $this->valueMinor($document),
            self::REVERSE_PERMISSION,
            $number,
            runChecks: ! $document->isReversed() && $stage === DocumentStage::Done,
        );
    }

    public function numberOf(Model $document): string
    {
        return "Adjustment #{$document->getKey()}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('inventory.stock.adjustments.index', ['focus' => $document->getKey()]);
    }

    public function originalDate(InventoryAdjustment $adjustment): Carbon
    {
        return Carbon::parse($adjustment->effective_date)->startOfDay();
    }

    /** What it moved, from its own movements (the reversal's movements excluded). */
    public function valueMinor(InventoryAdjustment $adjustment): int
    {
        return StockValue::sum($adjustment->movements()->whereNull('reverses_movement_id')->get())->absolute()->minor;
    }
}
