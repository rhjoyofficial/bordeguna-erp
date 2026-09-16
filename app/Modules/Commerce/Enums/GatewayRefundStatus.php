<?php

namespace App\Modules\Commerce\Enums;

/**
 * Where one request to send money back through SSLCommerz stands (slice P4).
 *
 * ⚠ `Confirmed` and `Refunded` are different on purpose, as `Proven` and `Paid`
 * are for a payment: confirmed means SSLCommerz says the money is back with the
 * customer; refunded means the ERP has booked it. The gap is visible and
 * retried, never rolled back.
 */
enum GatewayRefundStatus: string
{
    // Written, and SSLCommerz has not yet answered in a way that can be read.
    case Requested = 'requested';
    // SSLCommerz accepted it and is working on it.
    case Processing = 'processing';
    case Confirmed = 'confirmed';
    case Refunded = 'refunded';
    // SSLCommerz refused to start it.
    case Failed = 'failed';
    // SSLCommerz stopped it after accepting it.
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Sent to SSLCommerz, no answer yet',
            self::Processing => 'SSLCommerz is refunding it',
            self::Confirmed => 'Refunded, not yet booked',
            self::Refunded => 'Refunded',
            self::Failed => 'Refused by SSLCommerz',
            self::Cancelled => 'Cancelled by SSLCommerz',
        };
    }

    /**
     * ⚠⚠ Money that may be leaving, or has left and is not booked yet. It is
     * RESERVED on the order, so nobody can also pay it back by hand.
     *
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Requested, self::Processing, self::Confirmed];
    }

    public function isInFlight(): bool
    {
        return in_array($this, self::inFlight(), true);
    }

    /**
     * Counts against what a payment can still give back: everything except a
     * request SSLCommerz refused or stopped.
     *
     * @return list<self>
     */
    public static function takingMoney(): array
    {
        return [self::Requested, self::Processing, self::Confirmed, self::Refunded];
    }
}
