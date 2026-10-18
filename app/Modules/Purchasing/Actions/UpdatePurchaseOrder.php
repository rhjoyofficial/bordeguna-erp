<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PaymentTerms;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Edit a purchase order on the full page (purchase redesign PR1, 18 Oct
 * 2026), while NOTHING has happened to it yet: open, nothing received,
 * nothing billed (PurchaseOrder::isEditable). After that the receipt, return
 * and bill screens are how what happened is changed; history is never
 * rewritten.
 *
 * ⚠ The lines, charges and payment plan are written again from what was
 * typed, through the same arithmetic as a new order (CreatePurchaseOrder::
 * plan). The PO number stays.
 *
 * ⚠⚠ What was approved is what will be bought, so:
 *  - a PENDING order may be changed by whoever may raise orders; it stays
 *    pending, or is approved there and then when they may approve orders
 *    (their own authority, exactly as on create);
 *  - an APPROVED order may be changed only by somebody who may approve
 *    orders, and stays approved on their authority;
 *  - a REJECTED order is not edited: raise a new one.
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
    ): PurchaseOrder {
        $order->loadMissing('lines');

        if (! $order->isEditable()) {
            throw new InvalidArgumentException("{$order->po_number} can no longer be edited: goods have been received or billed against it, or it is closed. Use the receipt, return or bill screens.");
        }

        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order needs at least one line item.');
        }

        $status = $order->approvalRequest?->status;

        if ($status === ApprovalStatus::Rejected) {
            throw new InvalidArgumentException("{$order->po_number} was rejected. Raise a new purchase order instead.");
        }

        if ($status === ApprovalStatus::Approved && ! $by->can('purchasing.po.approve')) {
            throw new InvalidArgumentException("{$order->po_number} is approved. Only someone who may approve orders can change it now.");
        }

        $orderDate = CreatePurchaseOrder::resolveOrderDate($orderDate);
        $plan = CreatePurchaseOrder::plan($supplier, $lines, $orderDate, $details);

        $order = DB::transaction(function () use ($order, $supplier, $warehouse, $plan, $by, $expectedDate, $note, $orderDate, $terms, $details) {
            $order->update([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'order_date' => $orderDate,
                'expected_date' => $expectedDate,
                'note' => $note,
                ...PaymentTerms::columns($terms),
                ...CreatePurchaseOrder::headerColumns($details, $plan, $by),
                // The buyer stays who it was unless the page named another.
                'buyer_id' => $details['buyer_id'] ?? $order->buyer_id ?? $by->id,
            ]);

            $order->charges()->delete();
            $order->paymentSchedule()->delete();
            $order->lines()->delete();

            CreatePurchaseOrder::writeChildren($order, $plan);

            $request = $order->fresh('approvalRequest')->approvalRequest;

            if ($request->isPending() && $by->can('purchasing.po.approve')) {
                $request->approve($by);
            }

            return $order->fresh(['lines', 'approvalRequest']);
        });

        if ($order->approvalRequest->isPending()) {
            app(NotifyApprovers::class)->handle(
                businessId: $order->business_id,
                permission: 'purchasing.po.approve',
                summary: "Purchase order {$order->po_number} for {$supplier->name} was changed and waits for approval",
                excludeUserId: $by->id,
            );
        }

        return $order;
    }
}
