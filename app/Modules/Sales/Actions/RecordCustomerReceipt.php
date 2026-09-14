<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money in from a customer, against specific invoices.
 *
 * ⚠⚠ THE MIRROR OF RecordSupplierPayment, in every detail that matters. AR and
 * AP then behave identically and one mental model covers both - which is the
 * whole reason this reads like a reflection rather than a fresh design.
 *
 * ⚠ The document is the point and the journal is a consequence. Somebody
 * received money on a date, into an account, with a reference, against these
 * invoices.
 *
 * ⭐ Or FOR AN ORDER whose invoice does not exist yet (SSLCommerz slice P1, Joy
 * 13 September): `heldForOrder` is kept on the customer's account for that
 * order, and the order's first invoice takes it. The journal is the same
 * `Dr money account / Cr 1140` either way - the ageing tie-out assumes every
 * receipt credits 1140, and money with no invoice behind it is already credit on
 * account (C-GLA-11).
 */
class RecordCustomerReceipt
{
    public function __construct(private readonly PostSalesJournals $posting) {}

    /**
     * @param  array<int, array{invoice: SalesInvoice, amount: string}>  $allocations
     *
     * @throws ValidationException
     */
    public function handle(
        Customer $customer,
        MoneyAccount $moneyAccount,
        mixed $receivedOn,
        array $allocations,
        User $createdBy,
        ?string $reference = null,
        ?string $note = null,
        ?Consignment $consignment = null,
        ?SalesOrder $forOrder = null,
        ?string $heldForOrder = null,
        bool $provenByGateway = false,
    ): CustomerReceipt {
        $date = Carbon::parse($receivedOn)->startOfDay();
        $held = Money::ofTaka($heldForOrder ?? '0');

        if ($held->minor < 0) {
            throw ValidationException::withMessages([
                'amount' => 'An amount held for an order cannot be negative.',
            ]);
        }

        // ⚠ Money held on account belongs to an order or it is an advance nobody
        // can name - which is exactly what the old refusal below guarded against.
        if ($held->minor > 0 && $forOrder === null) {
            throw ValidationException::withMessages([
                'amount' => 'Money not taken by an invoice must be paid for a named order.',
            ]);
        }

        if ($forOrder !== null && ($forOrder->customer_id !== $customer->id || $forOrder->business_id !== $customer->business_id)) {
            throw ValidationException::withMessages([
                'sales_order_id' => "Order {$forOrder->order_number} belongs to a different customer.",
            ]);
        }

        AccountingPeriod::assertOpenFor($date);

        if ($moneyAccount->business_id !== $customer->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        // ⚠⚠ Cash a courier holds arrives in its account ONLY through a recorded
        // parcel outcome. A receipt typed into it by hand would say a courier
        // owes money for a parcel nobody can name, and its payout statement
        // would never reconcile.
        if ($moneyAccount->isCourier() && ($consignment === null || $consignment->money_account_id !== $moneyAccount->id)) {
            throw ValidationException::withMessages([
                'money_account_id' => "{$moneyAccount->name} is a courier's account. Money reaches it when a parcel's delivery is recorded, not as a receipt.",
            ]);
        }

        // ⚠⚠ The courier rule, for an online payment gateway (SSLCommerz slice P2):
        // money reaches its account only through a payment the gateway itself
        // proved. Typed in by hand it would say the gateway owes money for a
        // payment nobody can find in its settlement report.
        if ($moneyAccount->isGateway() && ! $provenByGateway) {
            throw ValidationException::withMessages([
                'money_account_id' => "{$moneyAccount->name} is a payment gateway's account. Money reaches it when the gateway confirms a payment, not as a receipt.",
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        $allocations = array_values(array_filter(
            $allocations,
            fn (array $entry) => Money::ofTaka($entry['amount'])->minor > 0,
        ));

        if ($allocations === [] && $held->isZero()) {
            throw ValidationException::withMessages([
                'allocations' => 'Say which invoices this receipt settles, and how much of each.',
            ]);
        }

        return DB::transaction(function () use ($customer, $moneyAccount, $date, $allocations, $createdBy, $reference, $note, $forOrder, $held) {
            $total = $held;

            foreach ($allocations as $index => $entry) {
                // ⚠⚠ RE-READ UNDER A LOCK inside the transaction. Two clerks
                // receipting the same invoice at once is the realistic way an
                // invoice reads as paid twice, and the re-read is what makes
                // this correct - `lockForUpdate` is a no-op on SQLite, so a
                // lock-only fix is green in the suite and races in production.
                /** @var SalesInvoice $invoice */
                $invoice = SalesInvoice::query()->whereKey($entry['invoice']->id)->lockForUpdate()->firstOrFail();

                if ($invoice->customer_id !== $customer->id) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.invoice" => "Invoice {$invoice->invoice_number} belongs to a different customer.",
                    ]);
                }

                $amount = Money::ofTaka($entry['amount']);
                $outstanding = $invoice->outstanding();

                // ⚠ Receiving more than an invoice owes is not a payment against
                // it. Refused rather than absorbed, because absorbing it would
                // make an invoice read as overpaid. Money paid ahead of an
                // invoice is `heldForOrder`, named against its order (P1, which
                // reversed the old plan of a separate event against 2150).
                if ($amount->minor > $outstanding->minor) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.amount" => "Invoice {$invoice->invoice_number} has only {$outstanding->format()} outstanding.",
                    ]);
                }

                $total = $total->plus($amount);
            }

            $receipt = CustomerReceipt::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'sales_order_id' => $forOrder?->id,
                'money_account_id' => $moneyAccount->id,
                'received_on' => $date,
                'amount' => $total->toTaka(),
                'reference' => $reference,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($allocations as $entry) {
                $receipt->allocations()->create([
                    'business_id' => $receipt->business_id,
                    'sales_invoice_id' => $entry['invoice']->id,
                    'amount' => Money::ofTaka($entry['amount'])->toTaka(),
                ]);
            }

            // ⚠⚠ Dr the money account, Cr Accounts Receivable - in the same
            // transaction as the document, so money received that the cash book
            // cannot see is not a state this can reach.
            $this->posting->forReceipt($receipt->fresh(['allocations', 'customer', 'moneyAccount']), $createdBy);

            return $receipt->fresh(['allocations', 'customer']);
        });
    }
}
