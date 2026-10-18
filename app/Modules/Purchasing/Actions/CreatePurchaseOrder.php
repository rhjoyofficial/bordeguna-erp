<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatePurchaseOrder
{
    /**
     * Each line carries its quantity and EITHER a unit cost OR a line total
     * (17 Oct 2026, Phase 1 part A); `entry_mode` says which was typed when
     * both are sent. A line with only `unit_cost`, as every caller before
     * that date sent, is a rate entry and behaves exactly as it did.
     *
     * @param  array<int, array{product: Product, quantity: string, unit_cost?: ?string, line_total?: ?string, entry_mode?: ?string}>  $lines
     */
    public function handle(
        Supplier $supplier,
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        ?string $expectedDate = null,
        ?string $note = null,
        ?string $orderDate = null,
    ): PurchaseOrder {
        if ($lines === []) {
            throw new InvalidArgumentException('A purchase order needs at least one line item.');
        }

        // ⭐ D-G (Joy, 18 Oct 2026): checked BEFORE the transaction so a refused
        // date never claims a PO number. Null means today.
        $orderDate = self::resolveOrderDate($orderDate);

        // ⚠ Resolved BEFORE the transaction: a refused line never claims a PO
        // number. The typed figure is kept; the other is derived from it.
        $lines = array_map(fn (array $line) => $line + ['entry' => PurchaseLineEntry::resolve(
            (string) $line['quantity'],
            isset($line['unit_cost']) ? (string) $line['unit_cost'] : null,
            isset($line['line_total']) ? (string) $line['line_total'] : null,
            $line['entry_mode'] ?? null,
        )], $lines);

        $order = DB::transaction(function () use ($supplier, $warehouse, $lines, $createdBy, $expectedDate, $note, $orderDate) {
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
                    'order_date' => $orderDate,
                    'expected_date' => $expectedDate,
                    'note' => $note,
                    'created_by' => $createdBy->id,
                ]),
            );

            foreach ($lines as $line) {
                $order->lines()->create([
                    'product_id' => $line['product']->id,
                    'quantity_ordered' => $line['quantity'],
                    'unit_cost' => $line['entry']['unit_cost'],
                    'line_total_paisa' => $line['entry']['line_total']->minor,
                    'entry_mode' => $line['entry']['entry_mode'],
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

    /**
     * The day the order was placed, as Y-m-d in Dhaka's calendar.
     *
     * ⭐ D-G (Joy, 18 Oct 2026): "many order done previous date but add later".
     * An earlier day is fine; a later one is not, because an order dated
     * tomorrow has not been placed yet. A day inside closed books is refused by
     * the one period check every document uses, whose sentence names the period.
     *
     * ⚠ The checks live here, not only on the screen, because this is the one
     * door every purchase order comes through.
     */
    public static function resolveOrderDate(?string $orderDate): string
    {
        $today = now()->toDateString();

        if ($orderDate === null || trim($orderDate) === '') {
            return $today;
        }

        $day = Carbon::parse(trim($orderDate))->startOfDay();

        if ($day->toDateString() > $today) {
            throw new InvalidArgumentException("The order date {$day->toDateString()} is in the future: an order is dated the day it was placed.");
        }

        AccountingPeriod::assertOpenFor($day);

        return $day->toDateString();
    }
}
