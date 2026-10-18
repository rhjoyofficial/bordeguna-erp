<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\MoneyAvailability;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money paid to a supplier before the bill (ruling P2, 18 Oct 2026).
 *
 *   pay()            Purchases > Payments, "Advance (no bill yet)": optional
 *                    purchase order, TDS held back when the supplier's rule
 *                    applies at payment. Dr 1160 / Cr the money account.
 *   opening()        Accounting > Opening balances: an advance the supplier
 *                    held at cutover. Dr 1160 / Cr 3900.
 *   forOverpayment() the excess of a supplier payment over its bills, after
 *                    the payer confirmed it. Posted on the payment's journal.
 *
 * ⚠ Same rules as a supplier payment, on purpose: an advance is money leaving
 * the bank. Closed month refused, future date refused, the account must be
 * this business's, active, and able to pay (finance policy B14).
 *
 * ⚠ VDS stays off while VAT is off (ruling P2: not activated in this phase).
 */
class RecordSupplierAdvance
{
    public function __construct(
        private readonly PostSupplierAdvanceJournals $posting,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    /**
     * @throws ValidationException
     */
    public function pay(
        Supplier $supplier,
        MoneyAccount $moneyAccount,
        mixed $paidOn,
        string $amount,
        User $createdBy,
        ?PurchaseOrder $purchaseOrder = null,
        ?string $reference = null,
        ?string $note = null,
        ?WithholdingRate $tdsRate = null,
        ?WithholdingRate $vdsRate = null,
    ): SupplierAdvance {
        $date = Carbon::parse($paidOn)->startOfDay();

        AccountingPeriod::assertOpenFor($date);

        if ($date->isFuture()) {
            throw ValidationException::withMessages(['paid_on' => 'An advance cannot be dated in the future.']);
        }

        $gross = Money::ofTaka($amount);

        if ($gross->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount of the advance.']);
        }

        self::assertMoneyAccount($moneyAccount, $supplier);

        if ($purchaseOrder !== null) {
            if ($purchaseOrder->supplier_id !== $supplier->id) {
                throw ValidationException::withMessages(['purchase_order_id' => "Purchase order {$purchaseOrder->po_number} is for a different supplier."]);
            }

            if ($purchaseOrder->status === PurchaseOrderStatus::Cancelled) {
                throw ValidationException::withMessages(['purchase_order_id' => "Purchase order {$purchaseOrder->po_number} is cancelled."]);
            }
        }

        $tds = self::withheld($tdsRate, 'tds', $supplier, $date, $gross);
        $vds = self::withheld($vdsRate, 'vds', $supplier, $date, $gross);
        $net = Money::ofMinor($gross->minor - $tds->minor - $vds->minor);

        if ($net->minor <= 0) {
            throw ValidationException::withMessages(['tds_rate_id' => 'The tax held back is more than the advance.']);
        }

        return DB::transaction(function () use ($supplier, $moneyAccount, $date, $gross, $tds, $vds, $tdsRate, $vdsRate, $createdBy, $purchaseOrder, $reference, $note, $net) {
            MoneyAvailability::assertCanPay($moneyAccount, $net, $date, 'money_account_id');

            $advance = $this->create($supplier, $date, [
                'kind' => 'payment',
                'purchase_order_id' => $purchaseOrder?->id,
                'money_account_id' => $moneyAccount->id,
                'amount' => $gross->toTaka(),
                'tds_rate_id' => $tds->isZero() ? null : $tdsRate?->id,
                'tds_amount' => $tds->toTaka(),
                'vds_rate_id' => $vds->isZero() ? null : $vdsRate?->id,
                'vds_amount' => $vds->toTaka(),
                'reference' => $reference,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            $this->posting->forAdvance($advance, $createdBy);

            return $advance->fresh(['supplier', 'moneyAccount', 'purchaseOrder']);
        });
    }

    /**
     * An advance the supplier already held at cutover, entered with the other
     * opening balances and under the same rules (finance decision A4/A5).
     *
     * @throws ValidationException
     */
    public function opening(Supplier $supplier, string $reference, mixed $date, string $amount, User $by): SupplierAdvance
    {
        $on = Carbon::parse($date)->startOfDay();
        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the advance still held by the supplier.']);
        }

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['date' => 'An opening balance is from before today.']);
        }

        AccountingPeriod::assertOpenFor($on);

        $reference = trim($reference);

        if (SupplierAdvance::query()->where('supplier_id', $supplier->id)->where('kind', 'opening')->where('reference', $reference)->live()->exists()) {
            throw ValidationException::withMessages(['reference' => "{$supplier->name} already has an opening advance {$reference}."]);
        }

        return DB::transaction(function () use ($supplier, $on, $money, $reference, $by) {
            $advance = $this->create($supplier, $on, [
                'kind' => 'opening',
                'amount' => $money->toTaka(),
                'reference' => $reference,
                'note' => 'Opening balance at cutover',
                'created_by' => $by->id,
            ]);

            $this->posting->forAdvance($advance, $by);

            return $advance;
        });
    }

    /**
     * Takes back an opening advance entered by mistake (Opening balances,
     * Remove): its journal is reversed and the advance is marked reversed,
     * kept rather than deleted so its number is never reused. Refused once any
     * of it has been applied or refunded.
     *
     * @throws ValidationException
     */
    public function removeOpening(SupplierAdvance $advance, User $by): void
    {
        DB::transaction(function () use ($advance, $by) {
            $advance = SupplierAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();

            if ($advance->kind !== 'opening' || $advance->isReversed()) {
                throw ValidationException::withMessages(['document' => 'That opening balance has already been removed.']);
            }

            if ($advance->applications()->live()->exists() || $advance->refunds()->live()->exists()) {
                throw ValidationException::withMessages(['document' => 'Part of this advance has been applied to a bill or refunded. Reverse that first.']);
            }

            $journal = $this->posting->existingJournal($advance);
            $reversal = $journal === null ? null : $this->reverseJournal->handle($journal, $by, null, 'Opening balance removed: entered by mistake');

            $advance->forceFill([
                'reversed_on' => Carbon::today(),
                'reversed_at' => now(),
                'reversed_by' => $by->id,
                'reversal_reason' => 'Opening balance removed: entered by mistake',
                'reversal_journal_id' => $reversal?->id,
            ])->save();
        });
    }

    /**
     * ⚠ Called by RecordSupplierPayment inside its transaction, after the
     * payer confirmed that the excess becomes an advance (ruling P2). No
     * journal here: the payment's journal carries the 1160 line.
     */
    public function forOverpayment(SupplierPayment $payment, Money $excess, ?WithholdingRate $tdsRate, User $by): SupplierAdvance
    {
        $tds = $tdsRate?->taxOn($excess) ?? Money::zero();

        return $this->create($payment->supplier, Carbon::parse($payment->paid_on), [
            'kind' => 'overpayment',
            'money_account_id' => $payment->money_account_id,
            'supplier_payment_id' => $payment->id,
            'amount' => $excess->toTaka(),
            'tds_rate_id' => $tds->isZero() ? null : $tdsRate?->id,
            'tds_amount' => $tds->toTaka(),
            'reference' => $payment->reference,
            'note' => 'Paid more than the bills owed: kept as an advance',
            'created_by' => $by->id,
        ]);
    }

    /**
     * SADV-2026-27-0001, the business's fiscal year in the middle.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function create(Supplier $supplier, Carbon $on, array $attributes): SupplierAdvance
    {
        $prefix = self::prefix('SADV', $supplier, $on);

        return DocumentNumber::claim(
            'number',
            fn () => DocumentNumber::next(SupplierAdvance::query(), 'number', $prefix),
            fn (string $number) => SupplierAdvance::create([
                'business_id' => $supplier->business_id,
                'number' => $number,
                'supplier_id' => $supplier->id,
                'paid_on' => $on->toDateString(),
                ...$attributes,
            ]),
        );
    }

    /** "SADV-2026-27-": the document's letters and the fiscal year of `$on`. */
    public static function prefix(string $letters, Supplier $supplier, Carbon $on): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($supplier->business_id);

        return $letters.'-'.FiscalYear::containing($on, $business)->label().'-';
    }

    /**
     * The money-account rules every supplier payment follows.
     *
     * @throws ValidationException
     */
    public static function assertMoneyAccount(MoneyAccount $moneyAccount, Supplier $supplier): void
    {
        if ($moneyAccount->business_id !== $supplier->business_id) {
            throw ValidationException::withMessages(['money_account_id' => 'That account belongs to a different business.']);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages(['money_account_id' => 'That account is no longer active.']);
        }

        if (($refusal = $moneyAccount->handRefusal()) !== null) {
            throw ValidationException::withMessages(['money_account_id' => $refusal]);
        }
    }

    /**
     * The tax held back at the advance: the rate must fit the supplier and be
     * in force on the date. VDS is refused while VAT does not apply.
     *
     * @throws ValidationException
     */
    private static function withheld(?WithholdingRate $rate, string $kind, Supplier $supplier, Carbon $date, Money $gross): Money
    {
        if ($rate === null) {
            return Money::zero();
        }

        $field = "{$kind}_rate_id";

        if ($kind === 'vds') {
            $business = Business::withoutGlobalScopes()->find($supplier->business_id);

            if ($business === null || ! $business->vatAppliesOn($date)) {
                throw ValidationException::withMessages([$field => 'VDS is not held back on an advance while VAT is off.']);
            }
        }

        if ($rate->kind !== $kind || ! $rate->appliesTo($supplier)
            || ! WithholdingRate::query()->whereKey($rate->id)->inForceOn($date)->exists()) {
            throw ValidationException::withMessages([$field => "{$rate->label()} does not apply to this supplier on that date."]);
        }

        return $rate->taxOn($gross);
    }
}
