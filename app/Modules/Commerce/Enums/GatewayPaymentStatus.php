<?php

namespace App\Modules\Commerce\Enums;

/**
 * Where one attempt to pay an order online stands (SSLCommerz slice P2b).
 *
 * ⚠ `Proven` and `Paid` are different on purpose: proven means the gateway has
 * the customer's money and said so in a way that checks out; paid means the ERP
 * has booked it. The gap between them is the one place money could be lost, so it
 * is a state somebody can see rather than a rollback nobody can.
 */
enum GatewayPaymentStatus: string
{
    case Initiated = 'initiated';
    case Proven = 'proven';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Waiting for the customer to pay',
            self::Proven => 'Paid, not yet booked',
            self::Paid => 'Paid',
            self::Failed => 'Payment failed',
            self::Cancelled => 'Cancelled by the customer',
            self::Expired => 'Expired unpaid',
        };
    }

    /** Has the gateway taken the customer's money? */
    public function tookMoney(): bool
    {
        return $this === self::Proven || $this === self::Paid;
    }
}
