<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Support\PaymentTerms;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Edit a purchase order on the full page (purchase redesign PR1, 18 Oct
 * 2026), while NOTHING has happened to it yet: open, nothing received,
 * nothing billed (PurchaseOrder::isEditable). After that the receipt, return
 * and bill screens, and Fix details, are how what happened is changed;
 * history is never rewritten.
 *
 * ⚠ The lines, charges and payment plan are written again from what was
 * typed, through the same arithmetic as a new order (CreatePurchaseOrder::
 * plan). The PO number stays.
 *
 * ⭐⭐ Corrections wave 1 (19 Oct 2026, FINAL-PLAN 2 and 3.3), by stage:
 *  - WAITING for approval: not edited. The sender withdraws it first
 *    (WithdrawPurchaseOrder), which makes it a draft again.
 *  - DRAFT (withdrawn or sent back): edited by whoever may raise orders, and
 *    sent for approval again on save (approved there and then when they may
 *    approve orders, their own authority as on create). No reason needed.
 *  - APPROVED, nothing happened yet: edited by whoever may raise orders (the
 *    Buyer), WITH a reason. Approval runs again when the total goes up, the
 *    supplier changes or the items change (a product added or removed, or
 *    more of one), unless the editor may approve orders. When only the value
 *    goes down it stays approved, the approvers are told, and it is listed in
 *    the Corrections report (FINAL-PLAN 1, "where I did not follow ChatGPT").
 *    The order date may change too (D-G).
 *  - REJECTED: not edited; Copy raises a new one.
 *
 * ⚠ A supplier change is refused while an advance paid to the old supplier
 * stands against the order: it would follow the wrong party.
 */
class UpdatePurchaseOrder
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $details
     */
    public function handle(
        PurchaseOrder $order,
        Supplier $supplier,
        Warehouse $warehouse,
        array $lines,
        User $by,
        ?string $expectedDate,
        ?string $note,
        ?string $orderDate,
        ?PaymentTerms $terms,
        array $details,
        ?ActionReason $reason = null,
    ): PurchaseOrder {
        $order->loadMissing(['lines', 'approvalRequest']);

        if (! $order->isEditable()) {
            throw new InvalidArgumentException("{$order->po_number} can no longer be edited: goods have been received or billed against it, or it is closed. Use the receipt, return or bill screens, or Fix details.");
        }

        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order needs at least one line item.');
        }

        $status = $order->approvalRequest?->status;

        if ($status === ApprovalStatus::Rejected) {
            throw new InvalidArgumentException("{$order->po_number} was rejected. Copy it to raise a new purchase order instead.");
        }

        if ($status === ApprovalStatus::Pending) {
            throw new InvalidArgumentException("{$order->po_number} is waiting for approval. Withdraw it first, then edit it.");
        }

        $approved = $status === ApprovalStatus::Approved;

        if ($approved && ($reason === null || mb_strlen(trim($reason->text)) < ActionReason::MIN)) {
            throw new InvalidArgumentException("{$order->po_number} is approved: say why it is being changed, in at least ".ActionReason::MIN.' characters.');
        }

        if ((int) $supplier->id !== (int) $order->supplier_id
            && SupplierAdvance::query()->whereNull('reversed_at')->where('purchase_order_id', $order->id)->exists()) {
            throw new InvalidArgumentException("An advance was paid against {$order->po_number} to its supplier. Reverse the advance before changing the supplier.");
        }

        $orderDate = CreatePurchaseOrder::resolveOrderDate($orderDate);
        $plan = CreatePurchaseOrder::plan($supplier, $lines, $orderDate, $details);

        $before = [
            'total' => $order->grandTotal()->minor,
            'supplier_id' => (int) $order->supplier_id,
            'items' => $this->items($order->lines),
        ];

        $outcome = null;

        $order = DB::transaction(function () use ($order, $supplier, $warehouse, $plan, $by, $expectedDate, $note, $orderDate, $terms, $details, $approved, $before, $reason, &$outcome) {
            // ⚠ Re-read under the lock RecordGoodsReceipt and CancelPurchaseOrder
            // take: a receipt landing between the check above and here would be
            // edited away underneath the goods.
            $current = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $current->load('lines')->isEditable()) {
                throw new InvalidArgumentException("{$current->po_number} can no longer be edited: goods have been received or billed against it.");
            }

            $current->update([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => $orderDate,
                'expected_date' => $expectedDate,
                'note' => $note,
                ...PaymentTerms::columns($terms),
                ...CreatePurchaseOrder::headerColumns($details, $plan, $by),
                // The buyer stays who it was unless the page named another.
                'buyer_id' => $details['buyer_id'] ?? $current->buyer_id ?? $by->id,
            ]);

            $current->charges()->delete();
            $current->paymentSchedule()->delete();
            $current->lines()->delete();

            CreatePurchaseOrder::writeChildren($current, $plan);

            $current = $current->fresh(['lines', 'approvalRequest']);
            $request = $current->approvalRequest;
            $mayApprove = $by->can('purchasing.po.approve');

            if (! $approved) {
                // A draft (withdrawn or sent back) goes for approval again.
                if ($request !== null && $request->status === ApprovalStatus::Returned) {
                    $request->resubmit();
                    $request->refresh();
                }

                if ($request !== null && $request->isPending() && $mayApprove) {
                    $request->approve($by);
                }

                return $current->fresh(['lines', 'approvalRequest']);
            }

            $after = $current->grandTotal()->minor;
            $rerun = $after > $before['total']
                || (int) $current->supplier_id !== $before['supplier_id']
                || $this->itemsChanged($before['items'], $this->items($current->lines));

            $properties = [
                'reason' => $reason?->display(),
                'old' => ['total' => Tk::of($before['total'])],
                'attributes' => ['total' => Tk::of($after)],
            ];

            if ($rerun && ! $mayApprove) {
                // ⚠ The engine reopens the SAME request (morphOne): approval
                // runs again from stage 1, and the note says why.
                $request->forceFill([
                    'status' => ApprovalStatus::Pending,
                    'current_stage' => 1,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_note' => "Changed after approval by {$by->name}: {$reason?->display()}",
                ])->save();

                $outcome = 'reapproval';
                CorrectionLog::event('approval_runs_again', $by, $current, $properties);
            } elseif ($after < $before['total']) {
                $outcome = 'lowered';
                CorrectionLog::event('value_lowered_after_approval', $by, $current, $properties);
            } else {
                CorrectionLog::event('edited_after_approval', $by, $current, $properties);
            }

            return $current->fresh(['lines', 'approvalRequest']);
        });

        if ($order->approvalRequest->isPending()) {
            app(NotifyApprovers::class)->handle(
                businessId: $order->business_id,
                permission: 'purchasing.po.approve',
                summary: "Purchase order {$order->po_number} for {$supplier->name} was changed and waits for approval",
                excludeUserId: $by->id,
            );
        } elseif ($outcome === 'lowered') {
            app(NotifyApprovers::class)->handle(
                businessId: $order->business_id,
                permission: 'purchasing.po.approve',
                summary: "Approved purchase order {$order->po_number} was lowered by {$by->name}: {$reason?->display()}",
                excludeUserId: $by->id,
            );
        }

        return $order;
    }

    /**
     * Quantity per product, scaled, for "the items changed".
     *
     * @param  iterable<PurchaseOrderLine>  $lines
     * @return array<int, int>
     */
    private function items(iterable $lines): array
    {
        $items = [];

        foreach ($lines as $line) {
            $items[(int) $line->product_id] = ($items[(int) $line->product_id] ?? 0) + PurchaseLineEntry::scaled((string) $line->quantity_ordered);
        }

        ksort($items);

        return $items;
    }

    /** A product added or removed, or more of one. Less of one is a lower value, not new items. */
    private function itemsChanged(array $before, array $after): bool
    {
        if (array_keys($before) !== array_keys($after)) {
            return true;
        }

        foreach ($after as $product => $quantity) {
            if ($quantity > $before[$product]) {
                return true;
            }
        }

        return false;
    }
}
