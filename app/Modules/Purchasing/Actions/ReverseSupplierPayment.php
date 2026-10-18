<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PUR-003 (owner decision 14 Oct 2026): a supplier payment reversed.
 *
 *   the journal   Dr the money account / Cr Accounts Payable (and TDS/VDS back)
 *   the bills     open again: the payment's allocations are released, so every
 *                 figure derived from them (outstanding, ageing, the payment
 *                 form, the cockpit, the cash forecast) sees the bill unpaid
 *   the record    the bills it had settled are kept on the payment
 *
 * A bill frozen because the WRONG payment was allocated to it can then be
 * reversed itself (ReverseSupplierBill refuses a paid bill).
 */
class ReverseSupplierPayment extends ReversePurchasingDocument
{
    public function __construct(ReverseJournal $reverseJournal, private readonly PostPurchaseJournals $posting)
    {
        parent::__construct($reverseJournal);
    }

    /**
     * @throws ValidationException
     */
    public function handle(SupplierPayment $payment, User $by, string $reason, bool $onOriginalDate = false): SupplierPayment
    {
        return $this->reverseDocument($payment, $by, $reason, $onOriginalDate);
    }

    protected function label(Model $document): string
    {
        return $document->reference ? "Supplier payment #{$document->id} ({$document->reference})" : "Supplier payment #{$document->id}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->paid_on->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return $this->posting->existingJournal($document);
    }

    protected function guard(Model $document): void
    {
        // ⚠ A payment a voucher created (finance decision C3) posted its line
        // on the VOUCHER's journal. The voucher is what is reversed, and
        // ReverseVoucher takes this payment with it.
        if ($document->voucher_id !== null) {
            throw ValidationException::withMessages([
                'reason' => "{$this->label($document)} was made by a payment voucher. Reverse the voucher under Accounting, Vouchers; the payment goes with it.",
            ]);
        }

        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): a settlement discount
        // taken with this payment took cost off batches; it is reversed first,
        // on its own, so the stock side is undone by the document that did it.
        $discount = SupplierDiscount::query()->where('supplier_payment_id', $document->id)->whereNull('reversed_at')->value('number');

        if ($discount !== null) {
            throw ValidationException::withMessages([
                'reason' => "{$this->label($document)} took settlement discount {$discount}. Reverse the discount on its bill first.",
            ]);
        }
    }

    protected function restore(Model $document): array
    {
        $allocations = SupplierPaymentAllocation::query()
            ->where('supplier_payment_id', $document->id)
            ->with('bill:id,bill_number')
            ->orderBy('id')
            ->get();

        $released = $allocations->map(fn (SupplierPaymentAllocation $allocation) => [
            'supplier_bill_id' => $allocation->supplier_bill_id,
            'bill_number' => $allocation->bill?->bill_number,
            'amount' => Money::ofTaka((string) $allocation->amount)->toTaka(),
        ])->all();

        SupplierPaymentAllocation::query()->whereKey($allocations->modelKeys())->delete();

        return ['reversed_allocations' => $released];
    }
}
