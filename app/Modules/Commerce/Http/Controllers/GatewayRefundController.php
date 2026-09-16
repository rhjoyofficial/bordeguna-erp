<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Actions\CheckGatewayRefund;
use App\Modules\Commerce\Actions\RequestGatewayRefund;
use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Money sent back through SSLCommerz, from the order screen (slice P4).
 *
 * ⚠⚠ Both routes are `sales.gateway.refund`, the Owner's alone (Joy, 16
 * September).
 */
class GatewayRefundController extends Controller
{
    public function store(Request $request, SalesOrder $order, RequestGatewayRefund $action): RedirectResponse
    {
        $data = $request->validate([
            'gateway_payment_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['required', 'string', 'max:255'],
        ]);

        // ⚠ Only a payment of THIS order: an id from another order 404s rather
        // than refunding a different customer.
        $payment = GatewayPayment::query()
            ->where('sales_order_id', $order->id)
            ->whereKey($data['gateway_payment_id'])
            ->firstOrFail();

        $refund = $action->handle($payment, (string) $data['amount'], $data['remarks'], $request->user());

        return $this->said($refund);
    }

    public function check(SalesOrder $order, GatewayRefund $refund, CheckGatewayRefund $action): RedirectResponse
    {
        abort_unless($refund->sales_order_id === $order->id, 404);

        return $this->said($action->handle($refund));
    }

    private function said(GatewayRefund $refund): RedirectResponse
    {
        return match ($refund->status) {
            GatewayRefundStatus::Refunded => back()->with('success', "SSLCommerz has refunded {$refund->amountMoney()->format()}, and it is booked."),
            GatewayRefundStatus::Confirmed => back()->with('error', "SSLCommerz has refunded it, and it is not in the books yet: {$refund->problem}"),
            GatewayRefundStatus::Processing => back()->with('success', 'SSLCommerz is refunding it. The ERP asks every 30 minutes and books it when SSLCommerz says the money is back with the customer.'),
            GatewayRefundStatus::Requested => back()->with('error', "Sent to SSLCommerz, no answer yet: {$refund->problem} The money stays reserved on this order meanwhile."),
            GatewayRefundStatus::Failed, GatewayRefundStatus::Cancelled => back()->with('error', "{$refund->problem} Nothing was refunded, and the money is free to pay back another way."),
        };
    }
}
