<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Purchasing\Corrections\PurchaseOrderActionPolicy;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderCharge;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Fix details on a purchase order after goods arrived (corrections wave 1,
 * 19 Oct 2026; FINAL-PLAN 3.3 "Purchase order after receipt").
 *
 * Text and dates that move no money: the order date (the day it was placed,
 * D-G), the expected date and the note. Before and after, who and why go to
 * the History; the order shows a small "Edited" badge.
 *
 * ⭐ The SUPPLIER only as a clerical slip, only while nothing is billed, and
 * only by somebody who may approve orders. The goods' GRNI credit names the
 * supplier on their ledger, so each live receipt's journal is reversed on its
 * own date and posted again naming the right one
 * (PostPurchaseJournals::renameReceiptSupplier). Refused, in a sentence, when
 * anything else already names the old supplier: a bill, an advance, a return
 * or a landed cost.
 */
class FixPurchaseOrderDetails
{
    public function __construct(
        private readonly PurchaseOrderActionPolicy $policy,
        private readonly PostPurchaseJournals $posting,
    ) {}

    /**
     * @param  array{order_date?: ?string, expected_date?: ?string, note?: ?string, supplier_id?: ?int}  $changes
     */
    public function handle(PurchaseOrder $order, array $changes, User $by, ActionReason $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $changes, $by, $reason) {
            $current = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current->load(['lines', 'approvalRequest', 'supplier']);
            $availability = $this->policy->availability($current, $by, DocumentAction::FixDetails);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            $before = [
                'order_date' => $current->orderDateString(),
                'expected_date' => $current->expected_date?->toDateString(),
                'note' => $current->note,
                'supplier' => $current->supplier?->name,
            ];

            $orderDate = $changes['order_date'] ?? $before['order_date'];

            if ($orderDate !== null && Carbon::parse($orderDate)->isAfter(Carbon::today())) {
                throw new InvalidArgumentException('An order cannot be dated in the future.');
            }

            $supplierId = isset($changes['supplier_id']) ? (int) $changes['supplier_id'] : (int) $current->supplier_id;
            $supplierChanged = $supplierId !== (int) $current->supplier_id;

            if ($supplierChanged) {
                $this->guardSupplierChange($current, $by);
            }

            $current->forceFill([
                'order_date' => $orderDate,
                'expected_date' => array_key_exists('expected_date', $changes) ? ($changes['expected_date'] ?: null) : $current->expected_date,
                'note' => array_key_exists('note', $changes) ? ($changes['note'] ?: null) : $current->note,
            ]);

            if ($supplierChanged) {
                $old = (int) $current->supplier_id;
                $current->supplier_id = $supplierId;

                // "This supplier's" charges follow the order's supplier.
                PurchaseOrderCharge::query()->where('purchase_order_id', $current->id)->where('supplier_id', $old)->update(['supplier_id' => $supplierId]);
            }

            if (! $current->isDirty()) {
                throw new InvalidArgumentException('Nothing was changed.');
            }

            $current->details_fixed_at = now();
            $current->save();

            if ($supplierChanged) {
                foreach (GoodsReceipt::query()->live()->where('purchase_order_id', $current->id)->orderBy('id')->get() as $receipt) {
                    try {
                        $this->posting->renameReceiptSupplier($receipt, $by, $reason->display());
                    } catch (PeriodClosedException) {
                        throw new InvalidArgumentException("The receipt of {$receipt->received_at?->format('j M Y')} is in a closed month, so the supplier cannot be changed here. Ask the Owner.");
                    }
                }
            }

            $current->refresh()->load('supplier');
            $after = [
                'order_date' => $current->orderDateString(),
                'expected_date' => $current->expected_date?->toDateString(),
                'note' => $current->note,
                'supplier' => $current->supplier?->name,
            ];

            CorrectionLog::event('fix_details', $by, $current, [
                'old' => array_diff_assoc($before, $after),
                'attributes' => array_diff_assoc($after, $before),
                'reason' => $reason->display(),
            ]);

            return $current;
        });
    }

    private function guardSupplierChange(PurchaseOrder $order, User $by): void
    {
        if (! $by->can(PurchaseOrderActionPolicy::APPROVER)) {
            throw new InvalidArgumentException('Only somebody who may approve orders can change the supplier.');
        }

        $lineIds = $order->lines->pluck('id');
        $billed = SupplierBill::query()->whereNull('reversed_at')
            ->where(fn ($q) => $q->where('purchase_order_id', $order->id)
                ->orWhereIn('id', SupplierBillLine::query()->whereIn('purchase_order_line_id', $lineIds)->select('supplier_bill_id')))
            ->exists();

        if ($billed || $order->lines->contains(fn ($line) => (float) $line->quantity_invoiced > 0)) {
            throw new InvalidArgumentException("{$order->po_number} is already billed, so its supplier cannot change. Reverse the bill first.");
        }

        if (SupplierAdvance::query()->whereNull('reversed_at')->where('purchase_order_id', $order->id)->exists()) {
            throw new InvalidArgumentException("An advance was paid to {$order->supplier?->name} against {$order->po_number}. Reverse the advance before changing the supplier.");
        }

        if (PurchaseReturn::query()->where('purchase_order_id', $order->id)->exists()) {
            throw new InvalidArgumentException("Goods on {$order->po_number} were returned to {$order->supplier?->name}, so its supplier cannot change.");
        }

        $receiptIds = GoodsReceipt::query()->where('purchase_order_id', $order->id)->pluck('id');

        if (LandedCost::query()->whereIn('goods_receipt_id', $receiptIds)->exists()) {
            throw new InvalidArgumentException("A landed cost is recorded on {$order->po_number}'s receipts, so its supplier cannot change here. Ask Finance.");
        }

        if (Supplier::query()->whereKey($order->supplier_id)->doesntExist()) {
            throw new InvalidArgumentException('The order\'s supplier could not be found.');
        }
    }
}
