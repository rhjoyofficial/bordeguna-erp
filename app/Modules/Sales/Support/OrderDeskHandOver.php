<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ Order desk (Joy, 31 Oct 2026): "Save and hand over" for goods the
 * customer takes away (pickup) in one press: the order, its challan for
 * everything ordered, and the invoice for that challan, which takes the money
 * already paid with the order (OrderDeskPayments). Whatever is still owed
 * stays on the invoice, as on any invoice.
 *
 * The same actions the order page's Deliver and the invoice screen use, in one
 * transaction: if the challan or the invoice is refused, neither is written
 * and the order stays as saved, with the reason on the order page.
 */
final class OrderDeskHandOver
{
    /** Checked before the order is written: the same gate as Deliver and Invoice. */
    public static function assertMay(User $user): void
    {
        if (! $user->can('sales.sell')) {
            throw ValidationException::withMessages(['then' => 'Handing goods over needs the selling permission. Save the order; the office hands it over.']);
        }
    }

    public static function start(SalesOrder $order, User $user): string
    {
        $order = $order->fresh(['approvalRequest', 'customer', 'lines.product', 'lines.deliveryLines']);
        $recorded = "Sales order {$order->order_number} recorded";

        if ($order->approvalRequest?->isPending()) {
            return "{$recorded}. It waits for approval; then it can be handed over.";
        }

        $warehouse = $order->warehouse_id !== null
            ? Warehouse::query()->find($order->warehouse_id)
            : Warehouse::query()->where('is_active', true)->where('is_default', true)->first();

        if ($warehouse === null) {
            return "{$recorded}, not handed over: choose the warehouse the goods leave from.";
        }

        $today = Carbon::today();

        try {
            $invoice = DB::transaction(function () use ($order, $warehouse, $user, $today) {
                $delivery = app(RecordDelivery::class)->handle(
                    customer: $order->customer,
                    warehouse: $warehouse,
                    lines: $order->lines
                        ->filter(fn (SalesOrderLine $line) => (float) $line->quantityRemaining() > 0)
                        ->map(fn (SalesOrderLine $line) => ['product' => $line->product, 'quantity' => $line->quantityRemaining(), 'orderLine' => $line])
                        ->values()->all(),
                    createdBy: $user,
                    deliveredAt: $today,
                    note: 'Handed over with the order',
                );

                $delivery = $delivery->fresh(['lines.product', 'lines.orderLine', 'customer']);
                $quantities = $delivery->lines->mapWithKeys(fn ($line) => [$line->id => (string) $line->quantity])->all();

                return app(RecordSalesInvoice::class)->handle(
                    customer: $order->customer,
                    lines: DeliveryBilling::lines($delivery, $quantities, app(ResolveSellingPrice::class), $today),
                    createdBy: $user,
                    invoiceDate: $today,
                    note: 'Handed over with the order',
                );
            });
        } catch (InsufficientStockException $e) {
            return "{$recorded}, not handed over: not enough stock. {$e->getMessage()}";
        } catch (ValidationException $e) {
            return "{$recorded}, not handed over: ".collect($e->errors())->flatten()->first();
        } catch (PeriodClosedException|MappingNotFound|NoSellingPriceException $e) {
            return "{$recorded}, not handed over: {$e->getMessage()}";
        } catch (Throwable $e) {
            report($e);

            return "{$recorded}, not handed over: something went wrong. Deliver it from the order page.";
        }

        $owed = $invoice->outstanding();

        return "{$recorded}, handed over and billed ({$invoice->invoice_number})."
            .($owed->isZero() ? ' Fully paid.' : " Still owed: Tk {$owed->toTaka()}.");
    }
}
