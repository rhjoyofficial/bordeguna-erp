<?php

namespace App\Modules\Sales\Enums;

/**
 * ⭐ B18 (Joy's initial plan R94, 25 Oct 2026): "we max do cash on delivery,
 * some comes on SSL." How a TYPED order is paid, kept in
 * `sales_orders.payment_method` beside the storefront's own values (`cod`,
 * `sslcommerz`), which this enum never writes: an order the ERP storefront
 * took through the gateway is the gateway's, and SettleOnlinePayments cancels
 * an unpaid one of those after a day. A typed "paid online" order is already
 * paid when it is typed, so it is a different value on purpose.
 */
enum OrderPayment: string
{
    // The same word the storefront writes: cash on delivery is cash on delivery.
    case CashOnDelivery = 'cod';
    // Paid online through SSLCommerz before it was typed: marked paid into the bank or wallet SSL settles into.
    case PaidOnline = 'ssl_paid';
    // Paid before, held as the customer's advance (IP3): used on the invoice.
    case Advance = 'advance';

    public function label(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Cash on delivery',
            self::PaidOnline => 'Paid online (SSL)',
            self::Advance => 'Paid before (advance)',
        };
    }
}
