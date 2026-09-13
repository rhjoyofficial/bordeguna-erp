<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * What this customer would owe if everything promised were delivered and
 * billed today: open invoice balances plus the value of approved, undelivered
 * order quantities at their agreed prices.
 *
 * ⚠ C-SO-3 (Joy, 2026-08-11): SHOWN, NEVER ENFORCED. This number appears next
 * to the credit limit on the order screen so a human can decide; nothing
 * anywhere blocks on it. Enforcement waits on the C-SLS-6 answer.
 *
 * ⚠ Pending orders are excluded on purpose - an unapproved order is not yet a
 * commitment, and counting it would double-charge the exposure the moment the
 * reviewing manager is looking at it.
 */
class CustomerExposure
{
    /**
     * @return array{credit_limit: string|null, open_invoices: string, undelivered_orders: string, total: string}
     */
    public function handle(Customer $customer): array
    {
        $openInvoices = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->with('allocations')
            ->get()
            ->reduce(
                fn (Money $carry, SalesInvoice $invoice) => $carry->plus($invoice->outstanding()),
                Money::zero(),
            );

        $undelivered = SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved))
            ->with('lines')
            ->get()
            ->reduce(
                fn (Money $carry, SalesOrder $order) => $carry->plus(
                    $order->lines->reduce(
                        // ⚠ Less that quantity's share of any agreed discount
                        // (slice 2.5a): exposure is what the customer will owe,
                        // and a bundle saving is part of what they agreed.
                        fn (Money $lineCarry, $line) => $lineCarry->plus(Money::ofMinor(
                            Money::ofProduct($line->quantityRemaining(), (string) $line->unit_price)->minor
                                - $line->discountShare($line->quantityRemaining())->minor
                        )),
                        Money::zero(),
                    )
                ),
                Money::zero(),
            );

        return [
            'credit_limit' => $customer->credit_limit !== null ? (string) $customer->credit_limit : null,
            'open_invoices' => $openInvoices->toTaka(),
            'undelivered_orders' => $undelivered->toTaka(),
            'total' => $openInvoices->plus($undelivered)->toTaka(),
        ];
    }
}
