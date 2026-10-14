<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatePurchaseOrder
{
    /**
     * @param  array<int, array{product: Product, quantity: string, unit_cost: string}>  $lines
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
            // ⚠ 14 Oct 2026 (SAL-001, audit #7): was "how many orders + 1",
            // which repeats a number the moment any order is removed and gave an
            // error page when two were saved together. Now the numerically
            // largest PO number + 1, retried on a collision.
            $order = DocumentNumber::claim(
                'po_number',
                fn () => DocumentNumber::next(PurchaseOrder::query(), 'po_number', 'PO-', 6),
                fn (string $number) => PurchaseOrder::create([
                    'po_number' => $number,
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $warehouse->id,
                    'status' => PurchaseOrderStatus::Open,
                    'expected_date' => $expectedDate,
                    'note' => $note,
                    'created_by' => $createdBy->id,
                ]),
            );

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
