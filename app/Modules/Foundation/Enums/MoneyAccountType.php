<?php

namespace App\Modules\Foundation\Enums;

/**
 * Where a business actually holds money.
 *
 * ⚠ Deliberately three kinds and not a free-text field: each answers a different
 * reconciliation question. A bank account reconciles to a statement, a cash
 * account to a physical count, and an MFS wallet to the provider's own history.
 */
enum MoneyAccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Mfs = 'mfs';

    /**
     * Cash on delivery a courier has collected and not yet paid out, reported
     * under 1145 Receivable from Couriers. It reconciles to the courier's payout
     * statement.
     *
     * ⚠ Nobody pays from it or receives into it by hand: a recorded parcel
     * outcome puts money in, and the courier's statement takes it out. See
     * MoneyAccount::scopeHandled.
     */
    case Courier = 'courier';

    /**
     * Money an online payment gateway (SSLCommerz) has taken from customers and
     * not yet settled to the bank, reported under 1146 Receivable from Payment
     * Gateways. It reconciles to the gateway's settlement report.
     *
     * ⚠ Nobody pays from it or receives into it by hand, the courier rule: a
     * proven gateway payment puts money in, and a settlement takes it out.
     */
    case Gateway = 'gateway';

    public function label(): string
    {
        return [
            'bank' => 'Bank account',
            'cash' => 'Cash',
            'mfs' => 'Mobile financial service',
            'courier' => 'Courier (cash on delivery)',
            'gateway' => 'Payment gateway (online payments)',
        ][$this->value] ?? $this->value;
    }
}
