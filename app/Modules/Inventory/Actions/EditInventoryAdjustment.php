<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Support\AdjustmentCause;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): the person who raised a stock
 * correction fixes it while it waits, or after withdrawing it (FINAL-PLAN 3.3,
 * "Stock adjustment, waiting: Edit, Withdraw").
 *
 * What may change: the date it happened, how many, the cost per unit of found
 * stock, what happened (the reason, which decides the type) and the note. The
 * product, the warehouse and the batch stay: a different product is a
 * different correction, raised on its own.
 *
 * ⚠⚠ Nothing has moved yet: stock and the ledger change only at approval
 * (ApproveInventoryAdjustment), so an edit rewrites the request, never stock.
 * The estimate is worked out again by the same rule as when it was raised, and
 * the approval starts again from its first stage: the approver of stage one
 * approved figures that no longer exist. A withdrawn one is sent again. Either
 * way it may approve itself under the limit, exactly as a new one would.
 *
 * ⚠ A waiting record is not a draft, so the change needs a reason (FINAL-PLAN
 * section 2); editing a withdrawn one (back to draft) does not.
 */
class EditInventoryAdjustment
{
    public function __construct(
        private readonly CreateInventoryAdjustment $create,
        private readonly ApproveInventoryAdjustment $approve,
    ) {}

    /**
     * @param  array{effective_date?: ?string, quantity: string, unit_cost?: ?string, reason_id: int, note?: ?string}  $changes
     */
    public function handle(InventoryAdjustment $adjustment, array $changes, User $by, ?ActionReason $why = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $changes, $by, $why) {
            /** @var InventoryAdjustment $current */
            $current = InventoryAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();
            $request = $current->approvalRequest()->lockForUpdate()->first();
            $status = $request?->status;

            if ((int) $current->created_by !== (int) $by->id) {
                throw new DomainException('Only the person who raised this correction can edit it.');
            }

            if (! in_array($status, [ApprovalStatus::Pending, ApprovalStatus::Returned], true)) {
                throw new DomainException('This correction was already decided, so it cannot be edited. Reverse it instead.');
            }

            if ($current->changesValueOnly()) {
                throw new DomainException('A cost change cannot be edited. Withdraw it and raise a new one.');
            }

            if ($status === ApprovalStatus::Pending && ($why === null || mb_strlen(trim($why->text)) < ActionReason::MIN)) {
                throw new InvalidArgumentException('Say why you are changing it, in at least '.ActionReason::MIN.' characters.');
            }

            $reason = Reason::query()->whereKey($changes['reason_id'])->where('context', 'inventory.stock_adjustment')->first()
                ?? throw new InvalidArgumentException('Choose one of the stock correction reasons.');
            $type = AdjustmentCause::typeFor($reason);

            if (AdjustmentCause::onlyReduces($type) && $current->direction === AdjustmentDirection::Increase) {
                throw new InvalidArgumentException("{$type->label()} can only take stock away. Withdraw this one and record found stock instead.");
            }

            if ((float) $changes['quantity'] <= 0) {
                throw new InvalidArgumentException('Enter a quantity above zero.');
            }

            $increase = $current->direction === AdjustmentDirection::Increase;
            $unitCost = $increase ? ($changes['unit_cost'] ?? null) : null;

            if ($increase && ($unitCost === null || $unitCost === '')) {
                throw new InvalidArgumentException('Stock that turned up needs a cost for each unit.');
            }

            // The same future and period rule as raising it.
            $appliesAt = EffectiveDate::resolve($changes['effective_date'] ?? $current->effective_date, 'effective_date');

            $current->fill([
                'type' => $type,
                'reason_id' => $reason->id,
                'quantity' => $changes['quantity'],
                'unit_cost' => $unitCost,
                'effective_date' => $appliesAt,
                'note' => $changes['note'] ?? null,
            ]);
            $current->estimated_value = $this->create->estimateValue(
                $current->direction,
                $current->product,
                $current->warehouse,
                (string) $changes['quantity'],
                $unitCost,
                $current->batch,
                AdjustmentCause::takesExpiredFirst($type),
            );
            $current->save();

            if ($status === ApprovalStatus::Returned) {
                $current->resubmitForApproval($by);
            } elseif ((int) $request->current_stage !== 1) {
                // The figures changed: every stage looks again.
                $request->update(['current_stage' => 1]);
            }

            CorrectionLog::event($status === ApprovalStatus::Returned ? 'edited_and_sent_again' : 'edited', $by, $current, array_filter([
                'reason' => $why?->display(),
                'quantity' => (string) $current->quantity,
                'effective_date' => $current->effective_date?->toDateString(),
            ], fn ($v) => $v !== null));

            $current = $current->fresh(['approvalRequest']);

            if ($this->create->maySelfApprove($by, $current)) {
                $this->approve->handle($current, $by, 'Within the approval limit, so approved as it was changed.');
            }

            $current = $current->fresh(['approvalRequest', 'movements', 'product']);

            // INV-006: whoever approves is told it changed, after commit.
            if ($current->approvalRequest?->isPending()) {
                DB::afterCommit(fn () => CreateInventoryAdjustment::notifyApprovers(
                    $current->business_id,
                    "Stock correction changed: {$current->direction->value} of {$current->quantity} x {$current->product?->name}",
                    $by->id,
                ));
            }

            return $current;
        });
    }
}
