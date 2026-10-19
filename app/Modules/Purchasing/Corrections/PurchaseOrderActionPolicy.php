<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Purchase orders in the corrections process (corrections wave 1, 19 Oct
 * 2026; FINAL-PLAN 2 and 3.3). The stage is worked out, never picked:
 *
 *   Waiting            sent for approval         Withdraw (the sender), Cancel (approver)
 *   Draft              withdrawn or sent back    Edit (sends it again), Cancel (approver)
 *   Approved, nothing  approved, no receipt,     Edit (approval runs again when the total
 *   happened yet       no bill                   goes up or the supplier or items change),
 *                                                Cancel (approver)
 *   Done               goods received or billed  Fix details (order date, expected date,
 *                                                note; the supplier only while unbilled,
 *                                                approver only), Cancel once nothing
 *                                                received is still in stock
 *   Cancelled, rejected                          nothing (Copy opens a new one)
 *
 * ⚠ A purchase order moves no money and no stock by itself, so its effect
 * profile is empty and the corrections service (Reverse, Correct) never runs on
 * it. Its actions are the module's own routes, which ask THIS policy again
 * before anything changes. It is registered so the History tab and the
 * generic actions endpoint work for it.
 *
 * Every action except editing a draft asks for a reason (server side).
 */
class PurchaseOrderActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'purchasing.purchase_order';

    public const BUYER = 'purchasing.po.create';

    public const APPROVER = 'purchasing.po.approve';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return PurchaseOrder::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.reports.view') || $user->can(self::BUYER);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var PurchaseOrder $document */
        $approval = $document->approvalRequest?->status;

        return match (true) {
            $document->status === PurchaseOrderStatus::Cancelled, $approval === ApprovalStatus::Rejected => DocumentStage::Done,
            $approval === ApprovalStatus::Pending => DocumentStage::Waiting,
            $approval === ApprovalStatus::Returned => DocumentStage::Draft,
            $this->somethingHappened($document) => DocumentStage::Done,
            default => DocumentStage::ApprovedNoEffect,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var PurchaseOrder $document */
        $stage = $this->stageOf($document);

        if ($this->isClosed($document)) {
            return new ActionSet($stage, []);
        }

        $verbs = match ($stage) {
            DocumentStage::Waiting => [DocumentAction::Withdraw, DocumentAction::Cancel],
            DocumentStage::Draft, DocumentStage::ApprovedNoEffect => [DocumentAction::Edit, DocumentAction::Cancel],
            default => [DocumentAction::FixDetails, DocumentAction::Cancel],
        };

        $actions = [];

        foreach ($verbs as $verb) {
            $availability = $this->availability($document, $user, $verb);

            // Somebody without the permission sees no button for it at all.
            if ($user->can($availability->permission)) {
                $actions[] = $availability;
            }
        }

        return new ActionSet($stage, $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var PurchaseOrder $document */
        $document->loadMissing(['lines', 'approvalRequest.requestedBy:id,name']);
        $stage = $this->stageOf($document);
        $number = $this->numberOf($document);
        $blockers = [];

        $permission = match ($action) {
            DocumentAction::Cancel => self::APPROVER,
            default => self::BUYER,
        };

        $offered = match ($action) {
            DocumentAction::Withdraw => $stage === DocumentStage::Waiting,
            DocumentAction::Edit => in_array($stage, [DocumentStage::Draft, DocumentStage::ApprovedNoEffect], true),
            DocumentAction::FixDetails => $stage === DocumentStage::Done,
            DocumentAction::Cancel => true,
            default => false,
        };

        if (! $user->can($permission)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->isClosed($document)) {
            $blockers[] = $document->status === PurchaseOrderStatus::Cancelled
                ? new Blocker('cancelled', "{$number} is cancelled. Copy it to raise a new order.")
                : new Blocker('rejected', "{$number} was rejected. Copy it to raise a new order.");
        } elseif (! $offered) {
            $blockers[] = new Blocker('not_offered', $this->notOffered($action, $stage, $number));
        }

        if ($blockers === [] && $action === DocumentAction::Withdraw && (int) $document->approvalRequest?->requested_by !== (int) $user->id) {
            $sender = $document->approvalRequest?->requestedBy?->name ?? 'the person who sent it';
            $blockers[] = new Blocker('not_sender', "Only {$sender}, who sent it, can withdraw it. An approver can reject or cancel it instead.");
        }

        if ($blockers === [] && $action === DocumentAction::Cancel && $stage === DocumentStage::Done && ! $document->hasNoOutstandingReceivedStock()) {
            $blockers[] = new Blocker('stock_received', "Goods received on {$number} are still in stock. Return or reverse them before cancelling.");
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $permission,
            primary: true,
            requiresReason: ! ($action === DocumentAction::Edit && $stage === DocumentStage::Draft),
            reasonContext: match ($action) {
                DocumentAction::Withdraw => 'withdrawal',
                DocumentAction::Cancel => 'cancellation',
                DocumentAction::FixDetails => 'fix_details',
                default => 'correction',
            },
            approval: ApprovalRoute::None,
        );
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->po_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('purchasing.purchase-orders.show', $document->getKey());
    }

    /** Received, billed or past Open: the order is a record of what happened now. */
    public function somethingHappened(PurchaseOrder $order): bool
    {
        $order->loadMissing('lines');

        return $order->status !== PurchaseOrderStatus::Open || ! $order->isEditable();
    }

    private function isClosed(PurchaseOrder $order): bool
    {
        return $order->status === PurchaseOrderStatus::Cancelled
            || $order->approvalRequest?->status === ApprovalStatus::Rejected;
    }

    private function notOffered(DocumentAction $action, DocumentStage $stage, string $number): string
    {
        return match ($action) {
            DocumentAction::Withdraw => "{$number} is not waiting for approval, so there is nothing to withdraw.",
            DocumentAction::Edit => $stage === DocumentStage::Waiting
                ? "{$number} is waiting for approval. Withdraw it first, then edit it."
                : "Goods were received or billed on {$number}, so it is no longer edited. Use Fix details.",
            DocumentAction::FixDetails => "Nothing was received or billed on {$number} yet. Edit it instead.",
            default => "{$action->label()} is not offered on a purchase order.",
        };
    }
}
