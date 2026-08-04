<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
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
    ): CustomerReceipt {
        $date = Carbon::parse($receivedOn)->startOfDay();

        AccountingPeriod::assertOpenFor($date);

        if ($moneyAccount->business_id !== $customer->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
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

        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Say which invoices this receipt settles, and how much of each.',
            ]);
        }

        return DB::transaction(function () use ($customer, $moneyAccount, $date, $allocations, $createdBy, $reference, $note) {
            $total = Money::zero();

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

                // ⚠ Receiving more than is owed is not a payment, it is an
                // advance from the customer - a different event against 2150.
                // Refused rather than absorbed, because absorbing it would make
                // an invoice read as overpaid and the receivable go negative.
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
