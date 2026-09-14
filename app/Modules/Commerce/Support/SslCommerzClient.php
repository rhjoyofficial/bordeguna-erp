<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Exceptions\GatewayUnavailable;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The three things the ERP asks of SSLCommerz (slice P2b): open a payment page,
 * check a callback's signature, and ask the Validation API what really happened.
 *
 * ⭐ Built against SSLCommerz's own PHP library (sslcommerz/SSLCommerz-PHP,
 * SslCommerzNotification), not against LaLaDia, whose integration has never taken
 * a real payment. That library checks the signature on EVERY callback before it
 * calls the Validation API; so does this.
 *
 * ⛔ The store password is sent to SSLCommerz and nowhere else: never logged,
 * never in an exception message, never stored on the attempt.
 */
final class SslCommerzClient
{
    public function __construct(private readonly StorePaymentGateway $gateway) {}

    public function baseUrl(): string
    {
        return $this->gateway->is_live ? 'https://securepay.sslcommerz.com' : 'https://sandbox.sslcommerz.com';
    }

    /**
     * Open the payment page for this attempt and return its address.
     *
     * @param  array{success: string, fail: string, cancel: string, ipn: string}  $urls
     *
     * @throws GatewayUnavailable
     */
    public function openSession(GatewayPayment $payment, SalesOrder $order, array $urls): string
    {
        $order->loadMissing('address', 'customer', 'lines');
        $address = $order->address;

        $name = $address?->recipient_name ?: ($order->customer?->name ?: 'Customer');
        $phone = $address?->recipient_phone ?: ($order->customer?->phone ?: '');
        $line = $address === null ? 'N/A' : trim(implode(', ', array_filter([$address->address_line, $address->area, $address->thana])));
        $city = $address?->district ?: 'Dhaka';
        $postcode = $address?->postcode ?: '1000';

        try {
            $response = Http::asForm()->timeout(20)->post($this->baseUrl().'/gwprocess/v4/api.php', [
                'store_id' => $this->gateway->merchant_id,
                'store_passwd' => $this->gateway->secret(),
                'total_amount' => $payment->amountMoney()->toTaka(),
                'currency' => 'BDT',
                'tran_id' => $payment->tran_id,
                'success_url' => $urls['success'],
                'fail_url' => $urls['fail'],
                'cancel_url' => $urls['cancel'],
                'ipn_url' => $urls['ipn'],
                'cus_name' => $name,
                // ⚠ SSLCommerz requires an email; a shop customer need not have
                // one. Never invented as somebody's real address.
                'cus_email' => $order->customer?->email ?: 'no-email@'.parse_url($urls['ipn'], PHP_URL_HOST),
                'cus_add1' => $line !== '' ? $line : 'N/A',
                'cus_city' => $city,
                'cus_postcode' => $postcode,
                'cus_country' => 'Bangladesh',
                'cus_phone' => $phone,
                // ⚠ The live gateway refuses a session without the shipping block
                // that the sandbox tolerates (LaLaDia's July failure).
                'shipping_method' => 'Courier',
                'num_of_item' => max(1, $order->lines->count()),
                'ship_name' => $name,
                'ship_add1' => $line !== '' ? $line : 'N/A',
                'ship_city' => $city,
                'ship_postcode' => $postcode,
                'ship_country' => 'Bangladesh',
                'product_name' => 'Order '.($order->shop_reference ?? $order->order_number),
                'product_category' => 'General',
                'product_profile' => 'general',
                'emi_option' => 0,
                'value_a' => $order->shop_reference ?? $order->order_number,
            ]);
        } catch (ConnectionException) {
            throw GatewayUnavailable::because('SSLCommerz could not be reached.');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ($body['status'] ?? null) !== 'SUCCESS' || empty($body['GatewayPageURL'])) {
            // ⚠ Logged without the request, which carries the password.
            Log::warning('SSLCommerz refused to open a payment session', [
                'tran_id' => $payment->tran_id,
                'http_status' => $response->status(),
                'reason' => is_array($body) ? ($body['failedreason'] ?? null) : null,
            ]);

            throw GatewayUnavailable::because(is_array($body) && ! empty($body['failedreason'])
                ? 'SSLCommerz refused the payment: '.$body['failedreason']
                : 'SSLCommerz did not open a payment page.');
        }

        return (string) $body['GatewayPageURL'];
    }

    /**
     * Does this callback carry SSLCommerz's signature for this store?
     *
     * ⭐ SSLCommerz's own algorithm: the fields named in `verify_key`, plus the md5
     * of the store password as `store_passwd`, sorted by key, joined as
     * `key=value&...`, md5'd, compared with `verify_sign`. A callback without a
     * signature is not signed.
     *
     * @param  array<string, mixed>  $callback
     */
    public function hasValidSignature(array $callback): bool
    {
        $sign = $callback['verify_sign'] ?? null;
        $keys = $callback['verify_key'] ?? null;
        $secret = $this->gateway->secret();

        if (! is_string($sign) || ! is_string($keys) || $sign === '' || $keys === '' || $secret === null) {
            return false;
        }

        $fields = [];

        foreach (explode(',', $keys) as $key) {
            if (array_key_exists($key, $callback)) {
                $fields[$key] = (string) $callback[$key];
            }
        }

        $fields['store_passwd'] = md5($secret);
        ksort($fields);

        $pairs = [];

        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return hash_equals(md5(implode('&', $pairs)), $sign);
    }

    /**
     * Ask the Validation API about a validation id. Null when it cannot be
     * reached or does not answer in a form that can be read.
     *
     * @return array<string, mixed>|null
     */
    public function validate(string $valId): ?array
    {
        try {
            $response = Http::timeout(20)->get($this->baseUrl().'/validator/api/validationserverAPI.php', [
                'val_id' => $valId,
                'store_id' => $this->gateway->merchant_id,
                'store_passwd' => $this->gateway->secret(),
                'v' => 1,
                'format' => 'json',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $body = $response->json();

        return $response->successful() && is_array($body) ? $body : null;
    }

    /**
     * Does the Validation API's answer prove this attempt was paid? Null when it
     * does, otherwise why not.
     *
     * ⚠ The official library accepts VALID or VALIDATED (the second means the
     * same transaction was validated before), the same tran_id, BDT, and an
     * amount within a taka. Stricter here on the amount: never LESS than asked,
     * to the paisa. More than asked is accepted, because the money is already
     * taken; the excess stays on the customer's account.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function whyNotProven(array $answer, GatewayPayment $payment): ?string
    {
        $status = $answer['status'] ?? null;

        if (! in_array($status, ['VALID', 'VALIDATED'], true)) {
            return 'SSLCommerz did not confirm the payment ('.(is_string($status) ? $status : 'no status').').';
        }

        if (($answer['tran_id'] ?? null) !== $payment->tran_id) {
            return 'SSLCommerz confirmed a different transaction.';
        }

        if (strtoupper((string) ($answer['currency'] ?? '')) !== 'BDT') {
            return 'SSLCommerz confirmed a payment in a currency other than BDT.';
        }

        $amount = $answer['amount'] ?? null;

        if (! is_scalar($amount) || ! preg_match('/^\d+(\.\d+)?$/', (string) $amount) || Money::ofTaka((string) $amount)->minor < $payment->amountMoney()->minor) {
            return 'SSLCommerz confirmed less than the order asked for.';
        }

        return null;
    }
}
