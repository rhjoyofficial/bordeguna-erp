<?php

namespace App\Modules\Commerce\Actions;

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Actions\RecordOrderPayment;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Book money the gateway proved it took (SSLCommerz slice P2b).
 *
 * In one transaction:
 * - the customer's payment on the order, through RecordOrderPayment into the
 *   shop's gateway account: `Dr 1146 / Cr 1140` (Joy's answer 1 - credit on
 *   account in 1140 until the order's invoice takes it);
 * - the gateway's fee, the gross less the `store_amount` it will settle:
 *   `Dr 6475 Payment Gateway Charge / Cr 1146`, so 1146 is what the gateway
 *   will actually deposit.
 *
 * ⚠⚠ ALWAYS RECORDED, even for an order cancelled or expired while the customer
 * was paying: the money is already gone from their card, and refusing it would
 * lose the record, not return the money. The order then shows refund owed.
 *
 * ⚠ If booking fails the attempt stays `proven` with the reason in words, and
 * this can run again. The proof was committed before this began.
 *
 * ⭐ An order waiting for its payment (P2c) reaches the approval queue here, once
 * the money is booked - never while it is only a basket somebody abandoned.
 */
final class BookGatewayPayment
{
    public function __construct(
        private readonly RecordOrderPayment $payments,
        private readonly PostJournal $postJournal,
    ) {}

    public function handle(GatewayPayment $payment): GatewayPayment
    {
        if ($payment->status !== GatewayPaymentStatus::Proven) {
            return $payment;
        }

        TenantScope::enter($payment->business_id);

        try {
            DB::transaction(function () use ($payment) {
                /** @var GatewayPayment $locked */
                $locked = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->with('store.paymentGateway.moneyAccount')->firstOrFail();

                if ($locked->status !== GatewayPaymentStatus::Proven) {
                    return;
                }

                $account = $locked->store->paymentGateway?->moneyAccount;

                if ($account === null || ! $account->isGateway() || $account->business_id !== $locked->business_id || $account->account_id === null) {
                    throw new RuntimeException('The shop has no payment gateway account to book it into.');
                }

                /** @var SalesOrder $order */
                $order = SalesOrder::query()->whereKey($locked->sales_order_id)->firstOrFail();
                $on = Carbon::parse($locked->proven_at)->timezone(config('app.timezone'))->startOfDay();
                $actor = SystemActor::user();

                $receipt = $this->payments->handle(
                    order: $order,
                    moneyAccount: $account,
                    receivedOn: $on,
                    amount: $locked->amountPaidMoney()->toTaka(),
                    createdBy: $actor,
                    reference: $locked->bank_tran_id ?: $locked->tran_id,
                    note: "Paid online through SSLCommerz, transaction {$locked->tran_id}",
                    moneyAlreadyTaken: true,
                    provenByGateway: true,
                );

                $fee = $locked->fee();

                if (! $fee->isZero()) {
                    $expense = AccountMapping::resolve('sales.gateway_fees', $on);

                    $this->postJournal->handle(new JournalDraft(
                        date: $on,
                        lines: [
                            JournalLineDraft::debit($expense->id, $fee, "SSLCommerz fee on {$locked->tran_id}"),
                            JournalLineDraft::credit(Account::findOrFail($account->account_id)->id, $fee, $account->label(), null, $account),
                        ],
                        memo: "SSLCommerz fee on {$locked->tran_id} ({$order->shop_reference})",
                        source: $locked,
                    ), $actor);
                }

                $locked->forceFill([
                    'status' => GatewayPaymentStatus::Paid,
                    'customer_receipt_id' => $receipt->id,
                    'problem' => null,
                ])->save();
            });
        } catch (Throwable $e) {
            Log::warning('An SSLCommerz payment was proven but could not be booked', [
                'tran_id' => $payment->tran_id,
                'reason' => $e->getMessage(),
            ]);

            $payment->forceFill(['problem' => mb_substr('Paid but not yet booked: '.$e->getMessage(), 0, 255)])->save();

            return $payment->fresh();
        }

        $this->queueForApproval($payment->fresh('salesOrder.customer')->salesOrder);

        return $payment->fresh();
    }

    /**
     * An order that waited for its payment goes to the people who confirm
     * orders, now that it is paid. An order already in the queue, or cancelled,
     * is left alone.
     */
    private function queueForApproval(SalesOrder $order): void
    {
        $order->loadMissing('approvalRequest');

        if ($order->approvalRequest !== null || $order->status === SalesOrderStatus::Cancelled) {
            return;
        }

        $order->submitForApproval(SystemActor::user());

        app(NotifyApprovers::class)->handle(
            businessId: $order->business_id,
            permission: 'sales.orders.approve',
            summary: "Sales order {$order->order_number} for {$order->customer?->name}, paid online",
        );
    }
}
