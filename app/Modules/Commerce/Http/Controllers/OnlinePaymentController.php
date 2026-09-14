<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Actions\CloseGatewayPayment;
use App\Modules\Commerce\Actions\ConfirmGatewayPayment;
use App\Modules\Commerce\Actions\StartGatewayPayment;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Exceptions\GatewayUnavailable;
use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\Store;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paying for a shop order online (SSLCommerz slice P2c): opening the payment
 * page, and SSLCommerz coming back.
 *
 * ⚠⚠ THE COMING BACK ARRIVES CROSS-SITE: SSLCommerz posts from its own pages,
 * so there is no CSRF token (the paths are exempted in bootstrap/app.php) and
 * no session or basket cookie (SameSite=lax). Nothing here depends on either.
 * The order page is reached by its shop reference, and nothing the callback says
 * is believed until ConfirmGatewayPayment has checked it.
 *
 * ⚠ The success and failure pages accept GET as well as POST. A redirect that
 * turns the gateway's POST into a GET must not strand a customer who has paid on
 * a 405; the payment itself is proven by the server notification regardless.
 */
class OnlinePaymentController extends Controller
{
    /**
     * Open (or reopen) the payment page for an order.
     */
    public function pay(Request $request, string $reference, StartGatewayPayment $start): RedirectResponse
    {
        $store = $this->store($request);
        $order = $this->order($store, $reference);

        try {
            $opened = $start->handle($order, $store, self::callbackUrls($request));
        } catch (GatewayUnavailable $e) {
            return redirect('/orders/'.$order->shop_reference)->with('payment_problem', $e->getMessage());
        }

        return redirect()->away($opened['url']);
    }

    public function success(Request $request, ConfirmGatewayPayment $confirm): RedirectResponse
    {
        $store = $this->store($request);
        $payment = $confirm->handle($store, $this->callback($request));

        return $this->backToOrder($store, $payment, $request);
    }

    public function fail(Request $request, CloseGatewayPayment $close): RedirectResponse
    {
        $store = $this->store($request);
        $payment = $close->handle($store, $this->callback($request), GatewayPaymentStatus::Failed);

        return $this->backToOrder($store, $payment, $request);
    }

    public function cancel(Request $request, CloseGatewayPayment $close): RedirectResponse
    {
        $store = $this->store($request);
        $payment = $close->handle($store, $this->callback($request), GatewayPaymentStatus::Cancelled);

        return $this->backToOrder($store, $payment, $request);
    }

    /**
     * SSLCommerz's own server telling the shop a payment happened. It is what
     * books a payment whose customer closed the tab before coming back.
     *
     * ⚠ Always a plain 200 once handled, whatever was found: SSLCommerz only
     * needs to know it was received, and its answer tells a caller nothing.
     */
    public function ipn(Request $request, ConfirmGatewayPayment $confirm): Response
    {
        $store = $this->store($request);
        $callback = $this->callback($request);

        $status = strtoupper((string) ($callback['status'] ?? ''));

        // A notification of a failed or cancelled payment closes the attempt the
        // same way the browser's return would; anything else is checked as a
        // payment.
        if (in_array($status, ['FAILED', 'CANCELLED', 'UNATTEMPTED', 'EXPIRED'], true)) {
            app(CloseGatewayPayment::class)->handle($store, $callback, $status === 'CANCELLED' ? GatewayPaymentStatus::Cancelled : GatewayPaymentStatus::Failed);
        } else {
            $confirm->handle($store, $callback);
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    private function backToOrder(Store $store, ?GatewayPayment $payment, Request $request): RedirectResponse
    {
        // ⚠ Found through the attempt, which the tran_id named. A callback naming
        // no attempt of this shop goes to the home page and reveals nothing.
        $reference = $payment === null
            ? null
            : SalesOrder::query()->whereKey($payment->sales_order_id)->where('store_id', $store->id)->value('shop_reference');

        return redirect($reference === null ? '/' : '/orders/'.$reference);
    }

    /**
     * @return array<string, mixed>
     */
    private function callback(Request $request): array
    {
        // Form fields on POST; the query string when a redirect made it a GET.
        return array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $request->all());
    }

    /**
     * Where SSLCommerz comes back to, on the shop hostname the customer is on.
     *
     * ⚠ Built from the request, whose scheme is https behind the trusted proxy. An
     * http address would be redirected to https, and a redirect turns the
     * gateway's POST into a GET that carries none of its fields.
     *
     * @return array{success: string, fail: string, cancel: string, ipn: string}
     */
    public static function callbackUrls(Request $request): array
    {
        $base = $request->getSchemeAndHttpHost().'/payments/sslcommerz';

        return [
            'success' => $base.'/success',
            'fail' => $base.'/fail',
            'cancel' => $base.'/cancel',
            'ipn' => $base.'/ipn',
        ];
    }

    private function order(Store $store, string $reference): SalesOrder
    {
        $order = SalesOrder::query()
            ->where('store_id', $store->getKey())
            ->where('channel', SalesChannel::Website)
            ->where('shop_reference', $reference)
            ->first();

        abort_if($order === null, 404);

        return $order;
    }

    private function store(Request $request): Store
    {
        $store = ResolveStorefront::current($request);

        abort_if($store === null, 404);

        return $store;
    }
}
