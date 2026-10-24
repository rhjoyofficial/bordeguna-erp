<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Actions\EditSalesOrder;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;

/**
 * ⭐ The buttons a sales order shows (corrections wave 1, FINAL-PLAN 2 and 3.2),
 * worked out on the server. At most two are primary; a blocked one stays, with
 * its sentence. The stage decides:
 *
 *   nothing delivered    Edit (sales.orders.record; one's own order for a field
 *                        officer) and Cancel (sales.orders.cancel); Copy beside
 *   partly delivered     Edit the undelivered part and Close short (both Owner,
 *                        Manager: sales.orders.cancel); Copy beside
 *   delivered, closed,   Copy only
 *   cancelled, rejected
 *
 * ⚠ Hiding a button is not security: SalesOrderController asks the same rules
 * again, and EditSalesOrder refuses under its lock.
 */
final class SalesOrderActions
{
    /** @return list<array{action: string, label: string, allowed: bool, primary: bool, reason: ?string}> */
    public static function for(SalesOrder $order, User $user): array
    {
        $order->loadMissing('lines', 'approvalRequest');
        $delivered = $order->lines->contains(fn (SalesOrderLine $line) => (float) $line->quantity_delivered > 0.00005);
        $own = $user->can('sales.reports.view') || (int) $order->created_by === (int) $user->id;
        $notEditable = EditSalesOrder::whyNotEditable($order);
        $actions = [];

        $mayEdit = $delivered ? $user->can('sales.orders.cancel') : ($user->can('sales.orders.record') && $own);

        if ($mayEdit) {
            $actions[] = self::row('edit', $delivered ? 'Edit undelivered part' : 'Edit', $notEditable, true);
        }

        if (! $delivered && $user->can('sales.orders.cancel') && $order->status === SalesOrderStatus::Open) {
            $actions[] = self::row('cancel', 'Cancel', $order->approvalRequest?->status === ApprovalStatus::Rejected ? "Order {$order->order_number} was rejected and promises nothing." : null, true);
        }

        if ($delivered && $user->can('sales.orders.cancel') && $order->status === SalesOrderStatus::PartiallyDelivered) {
            $actions[] = self::row('close', 'Close short', null, true);
        }

        if ($user->can('sales.orders.record')) {
            $actions[] = self::row('copy', 'Copy', null, false);
        }

        return $actions;
    }

    /** What a refund of the order's held money would need, said beside Cancel. */
    public static function cancelNote(SalesOrder $order): ?string
    {
        $held = $order->advanceHeld();

        return $held->minor > 0 ? "This order holds {$held->format()} the customer paid; after cancelling, pay it back from the order." : null;
    }

    /** @return array{action: string, label: string, allowed: bool, primary: bool, reason: ?string} */
    private static function row(string $action, string $label, ?string $reason, bool $primary): array
    {
        return ['action' => $action, 'label' => $label, 'allowed' => $reason === null, 'primary' => $primary, 'reason' => $reason];
    }

    /**
     * The order as the order form starts from it: for Edit (with line ids and
     * what left), or for Copy (a new order of the same lines, dated today).
     *
     * @return array<string, mixed>
     */
    public static function form(SalesOrder $order, bool $copy): array
    {
        $order->loadMissing('lines', 'address');
        $address = $order->address;

        return [
            'id' => $copy ? null : $order->id,
            'order_number' => $order->order_number,
            'mode' => $copy ? 'copy' : ($order->lines->contains(fn ($l) => (float) $l->quantity_delivered > 0.00005) ? 'edit_undelivered' : 'edit'),
            'customer_id' => $order->customer_id,
            'brand_id' => $order->brand_id,
            'warehouse_id' => $order->warehouse_id,
            'ordered_at' => $copy ? null : $order->ordered_at?->toDateString(),
            'expected_date' => $copy ? null : $order->expected_date?->toDateString(),
            'note' => $order->note,
            'special_instructions' => $order->special_instructions,
            'delivery_charge' => $order->deliveryCharge()->isZero() ? '' : Money::ofTaka((string) $order->delivery_charge)->toTaka(),
            'weight_grams' => $order->weight_grams,
            'address' => [
                'name' => $address?->recipient_name ?? '',
                'phone' => $address?->recipient_phone ?? '',
                'district' => $address?->district ?? '',
                'thana' => $address?->thana ?? '',
                'area' => $address?->area ?? '',
                'address_line' => $address?->address_line ?? '',
                'landmark' => $address?->landmark ?? '',
            ],
            'lines' => $order->lines->map(fn (SalesOrderLine $line) => [
                'id' => $copy ? null : $line->id,
                'product_id' => $line->product_id,
                'quantity' => rtrim(rtrim(number_format((float) $line->quantity_ordered, 4, '.', ''), '0'), '.'),
                'unit_price' => rtrim(rtrim(number_format((float) $line->unit_price, 4, '.', ''), '0'), '.'),
                // ⭐ 25 Oct 2026 (Q12 = A): the line discount opens in its box.
                'discount' => number_format((float) $line->line_discount, 2, '.', ''),
                'description' => $line->description ?? '',
                'delivered' => $copy ? 0 : (float) $line->quantity_delivered,
            ])->values()->all(),
        ];
    }
}
