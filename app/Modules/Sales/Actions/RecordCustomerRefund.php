<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money physically going back to a customer (C-SR-6).
 *
 * ⚠⚠ LINKED TO A RETURN (handle) OR TO AN ORDER (forOrder), and capped by what
 * that document still owes the customer - the same structural cap the supplier
 * credit note carries, for the same reason: an uncapped refund is how a
 * receivable goes quietly negative. A refund with neither behind it is a
 * goodwill payment, a different event Joy kept out of scope.
 *
 * ⚠ The counter case - cash back in hand for a walk-in return - is this
 * document recorded in the same DB transaction as the return, permission
 * Owner/Manager (C-SR-9). The default remains credit-to-account: the
 * distributor's credit simply nets against their next invoice.
 */
class RecordCustomerRefund
{
    public function __construct(private readonly PostSalesJournals $posting) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        SalesReturn $salesReturn,
        MoneyAccount $moneyAccount,
        string $amount,
        User $createdBy,
        mixed $refundedOn = null,
        ?string $reference = null,
        ?string $note = null,
        // ⭐ A Payment voucher line on a customer (party ledgers, 20 Oct 2026):
        // the refund belongs to the voucher, whose own journal carries the
        // debit to Accounts Receivable and checks the money account.
        ?int $voucherId = null,
    ): CustomerRefund {
        $date = ($refundedOn === null ? Carbon::now() : Carbon::parse($refundedOn))->startOfDay();

        AccountingPeriod::assertOpenFor($date);

        if ($moneyAccount->business_id !== $salesReturn->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        // A courier's or a gateway's account pays nobody back by hand.
        if ($moneyAccount->isCourier() || $moneyAccount->isGateway()) {
            throw ValidationException::withMessages([
                'money_account_id' => "{$moneyAccount->name} holds money for somebody else. Pay the customer back from a bank, cash or mobile wallet account.",
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        $refunding = Money::ofTaka($amount);

        if ($refunding->minor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A refund must be greater than zero.',
            ]);
        }

        // Finance policy B14: no cash box or wallet below zero, a bank only to its overdraft.
        // A voucher checks its whole total itself (PostVoucher).
        if ($voucherId === null) {
            MoneyAvailability::assertCanPay($moneyAccount, $refunding, $date, 'money_account_id');
        }

        return DB::transaction(function () use ($salesReturn, $moneyAccount, $refunding, $createdBy, $date, $reference, $note, $voucherId) {
            // ⚠ Re-read under a lock: two clerks refunding the same return at
            // once is how a credit is paid back twice, and lockForUpdate is a
            // no-op on SQLite - the RE-READ is what makes this correct.
            $return = SalesReturn::query()->whereKey($salesReturn->id)->lockForUpdate()->firstOrFail();

            $refundable = $return->refundable();

            if ($refunding->minor > $refundable->minor) {
                throw ValidationException::withMessages([
                    'amount' => "Return {$return->return_number} has only {$refundable->format()} left to refund.",
                ]);
            }

            $refund = CustomerRefund::create([
                'business_id' => $return->business_id,
                'customer_id' => $return->customer_id,
                'sales_return_id' => $return->id,
                'money_account_id' => $moneyAccount->id,
                'amount' => $refunding->toTaka(),
                'refunded_on' => $date,
                'reference' => $reference,
                'note' => $note,
                'created_by' => $createdBy->id,
                'voucher_id' => $voucherId,
            ]);

            // Dr AR (party = customer) / Cr the money account - in the same
            // transaction as the document.
            if ($voucherId === null) {
                $this->posting->forCustomerRefund($refund->fresh(['customer', 'moneyAccount']), $createdBy);
            }

            return $refund->fresh(['customer', 'moneyAccount', 'salesReturn']);
        });
    }

    /**
     * Money paid back out of what an order holds (SSLCommerz slice P1): the
     * customer paid for an order that will not be invoiced for all of it - it was
     * cancelled, or the parcel came back.
     *
     * ⚠⚠ CAPPED BY WHAT THE ORDER STILL HOLDS, read under the order's lock. The
     * same structural cap a return-linked refund carries, for the same reason: an
     * uncapped refund is how a receivable goes quietly negative.
     *
     * ⚠ Refused from a courier's or a gateway's account: each holds money for the
     * business, and neither pays a customer back by hand. The one exception is
     * slice P4: booking a refund SSLCommerz itself has CONFIRMED, named by
     * `$throughGateway`, out of the gateway account the payment was booked into.
     *
     * @throws ValidationException
     */
    public function forOrder(
        SalesOrder $order,
        MoneyAccount $moneyAccount,
        string $amount,
        User $createdBy,
        mixed $refundedOn = null,
        ?string $reference = null,
        ?string $note = null,
        ?GatewayRefund $throughGateway = null,
        // ⭐ As handle(): made by a Payment voucher (20 Oct 2026).
        ?int $voucherId = null,
    ): CustomerRefund {
        $date = ($refundedOn === null ? Carbon::now() : Carbon::parse($refundedOn))->startOfDay();

        AccountingPeriod::assertOpenFor($date);

        if ($moneyAccount->business_id !== $order->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        $bookingGatewayRefund = $throughGateway !== null
            && $moneyAccount->isGateway()
            && $throughGateway->status === GatewayRefundStatus::Confirmed
            && $throughGateway->sales_order_id === $order->id;

        if ($moneyAccount->isCourier() || ($moneyAccount->isGateway() && ! $bookingGatewayRefund)) {
            throw ValidationException::withMessages([
                'money_account_id' => "{$moneyAccount->name} holds money for somebody else. Pay the customer back from a bank, cash or mobile wallet account.",
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        $refunding = Money::ofTaka($amount);

        if ($refunding->minor <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A refund must be greater than zero.',
            ]);
        }

        // Finance policy B14: no cash box or wallet below zero, a bank only to its overdraft.
        // A voucher checks its whole total itself (PostVoucher).
        if ($voucherId === null) {
            MoneyAvailability::assertCanPay($moneyAccount, $refunding, $date, 'money_account_id');
        }

        return DB::transaction(function () use ($order, $moneyAccount, $refunding, $createdBy, $date, $reference, $note, $throughGateway, $bookingGatewayRefund, $voucherId) {
            /** @var SalesOrder $order */
            $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->with('customer')->firstOrFail();

            $held = $order->advanceHeld();

            // ⚠ The confirmed gateway refund being booked is itself reserved in
            // advanceHeld(), so it is added back for its own booking, and only
            // its own amount: it cannot book more than was asked of SSLCommerz.
            if ($bookingGatewayRefund) {
                if ($refunding->minor !== $throughGateway->amountMoney()->minor) {
                    throw ValidationException::withMessages([
                        'amount' => 'A refund through SSLCommerz is booked for exactly what SSLCommerz was asked to send back.',
                    ]);
                }

                $held = $held->plus($throughGateway->amountMoney());
            }

            if ($refunding->minor > $held->minor) {
                throw ValidationException::withMessages([
                    'amount' => $held->minor <= 0
                        ? "Order {$order->order_number} holds none of the customer's money."
                        : "Order {$order->order_number} holds only {$held->format()} of the customer's money.",
                ]);
            }

            $refund = CustomerRefund::create([
                'business_id' => $order->business_id,
                'customer_id' => $order->customer_id,
                'sales_return_id' => null,
                'sales_order_id' => $order->id,
                'money_account_id' => $moneyAccount->id,
                'amount' => $refunding->toTaka(),
                'refunded_on' => $date,
                'reference' => $reference,
                'note' => $note ?? "Refund for order {$order->order_number}",
                'created_by' => $createdBy->id,
                'voucher_id' => $voucherId,
            ]);

            if ($voucherId === null) {
                $this->posting->forCustomerRefund($refund->fresh(['customer', 'moneyAccount']), $createdBy);
            }

            return $refund->fresh(['customer', 'moneyAccount', 'salesOrder']);
        });
    }
}
