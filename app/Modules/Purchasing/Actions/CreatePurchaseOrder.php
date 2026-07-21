<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatePurchaseOrder
{
    /**
     * @param  array<int, array{product: \App\Modules\Inventory\Models\Product, quantity: string, unit_cost: string}>  $lines
     */
    public function handle(
        Supplier $supplier,
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        ?string $expectedDate = null,
        ?string $note = null,
    ): PurchaseOrder {
        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order needs at least one line item.');
        }

        $order = DB::transaction(function () use ($supplier, $warehouse, $lines, $createdBy, $expectedDate, $note) {
            $poNumber = sprintf('PO-%06d', PurchaseOrder::count() + 1);

            $order = PurchaseOrder::create([
                'po_number' => $poNumber,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => PurchaseOrderStatus::Open,
                'expected_date' => $expectedDate,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($lines as $line) {
                $order->lines()->create([
                    'product_id' => $line['product']->id,
                    'quantity_ordered' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ]);
            }

            $order->submitForApproval($createdBy);

            if ($createdBy->can('purchasing.po.approve')) {
                $order->approvalRequest->approve($createdBy);
            }

            return $order->fresh(['lines', 'approvalRequest']);
        });

        // Only notify when it actually needs review — a self-approved PO doesn't.
        if ($order->approvalRequest->isPending()) {
            app(NotifyApprovers::class)->handle(
                businessId: $order->business_id,
                permission: 'purchasing.po.approve',
                summary: "Purchase order {$order->po_number} for {$supplier->name}",
                excludeUserId: $createdBy->id,
            );
        }

        return $order;
    }
}
