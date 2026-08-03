<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays a supplier, against specific bills.
 *
 * ⚠⚠ THE EVENT THAT DID NOT EXIST. Until now the ERP could post a liability to
 * Accounts Payable and had no way to settle it: the only related action set a
 * payment-status string that moved no money and reached no ledger. Payables
 * would have grown forever.
 *
 * ⚠ The document is the point and the journal is a consequence. Somebody moved
 * money on a date, from an account, with a reference, against these invoices.
 */
class RecordSupplierPayment
{
    public function __construct(private readonly PostPurchaseJournals $posting) {}

    /**
     * @param  array<int, array{bill: SupplierBill, amount: string}>  $allocations
     *
     * @throws ValidationException
     */
    public function handle(
        Supplier $supplier,
        MoneyAccount $moneyAccount,
        mixed $paidOn,
        array $allocations,
        User $createdBy,
        ?string $reference = null,
        ?string $note = null,
    ): SupplierPayment {
        $date = Carbon::parse($paidOn)->startOfDay();

        // ⚠ A payment dated into a closed month would change a figure somebody
        // has already reported. Same lock as everything else.
        AccountingPeriod::assertOpenFor($date);

        if ($moneyAccount->business_id !== $supplier->business_id) {
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
                'allocations' => 'Say which bills this payment settles, and how much of each.',
            ]);
        }

        return DB::transaction(function () use ($supplier, $moneyAccount, $date, $allocations, $createdBy, $reference, $note) {
            $total = Money::zero();

            foreach ($allocations as $index => $entry) {
                /** @var SupplierBill $bill */
                // ⚠⚠ RE-READ UNDER A LOCK inside the transaction. Two clerks
                // paying the same invoice at once is the realistic way a
                // supplier gets paid twice, and the re-read is what makes this
                // correct - `lockForUpdate` is a no-op on SQLite, so a
                // lock-only fix is green in the suite and races in production.
                // Third application of the I3 lesson.
                $bill = SupplierBill::query()->whereKey($entry['bill']->id)->lockForUpdate()->firstOrFail();

                if ($bill->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.bill" => "Bill {$bill->bill_number} belongs to a different supplier.",
                    ]);
                }

                $amount = Money::ofTaka($entry['amount']);
                $outstanding = $bill->outstanding();

                // ⚠ Paying more than is owed is not a payment, it is an advance
                // to the supplier - a different event with a different account.
                // Refused rather than absorbed, because absorbing it would make
                // a bill read as overpaid and the payable go negative.
                if ($amount->minor > $outstanding->minor) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.amount" => "Bill {$bill->bill_number} has only {$outstanding->format()} outstanding.",
                    ]);
                }

                $total = $total->plus($amount);
            }

            $payment = SupplierPayment::create([
                'supplier_id' => $supplier->id,
                'money_account_id' => $moneyAccount->id,
                'paid_on' => $date,
                'amount' => $total->toTaka(),
                'reference' => $reference,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($allocations as $entry) {
                $payment->allocations()->create([
                    'business_id' => $payment->business_id,
                    'supplier_bill_id' => $entry['bill']->id,
                    'amount' => Money::ofTaka($entry['amount'])->toTaka(),
                ]);
            }

            // ⚠⚠ Dr Accounts Payable, Cr the money account - posted in the same
            // transaction as the document, so a payment the cash book cannot see
            // is not a state this can reach.
            $this->posting->forPayment($payment->fresh(['allocations', 'supplier', 'moneyAccount']), $createdBy);

            return $payment->fresh(['allocations', 'supplier']);
        });
    }
}
