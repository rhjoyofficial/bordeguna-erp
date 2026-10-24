<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ The three documents one counter sale wrote (corrections wave 1, 20 Oct
 * 2026, FINAL-PLAN 3.2 "Counter sale: Void sale"), found again from its
 * invoice.
 *
 * ⚠⚠ THERE IS NO "COUNTER SALE" FLAG ON ANY DOCUMENT, on purpose (see
 * RecordCounterSale: the counter is the ordinary engine, three steps in one
 * transaction). So the bundle is RECOGNISED by what that transaction leaves
 * behind, all of which must hold:
 *
 *   - every invoice line bills a line of ONE challan, and that challan's lines
 *     are on no order and are billed by this invoice in full;
 *   - the challan and the invoice were written by the same person, on the same
 *     day, within a minute of each other, and no courier was ever involved;
 *   - the receipts are those of the same person, in the same minute, whose
 *     whole amount went to this invoice and nowhere else.
 *
 * A distributor invoice raised on its own screen weeks after the challan fails
 * the minute rule, so it is credited, never "voided".
 */
final class CounterSaleBundle
{
    private const SAME_TRANSACTION_SECONDS = 60;

    /** @param Collection<int, CustomerReceipt> $receipts */
    private function __construct(
        public readonly SalesInvoice $invoice,
        public readonly Delivery $delivery,
        public readonly Collection $receipts,
    ) {}

    public static function of(SalesInvoice $invoice): ?self
    {
        if ($invoice->is_opening || $invoice->debit_note_for_invoice_id !== null || $invoice->created_at === null) {
            return null;
        }

        $lines = $invoice->lines()->get(['id', 'delivery_line_id', 'quantity', 'billed_delivery_for_order_id']);

        if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->delivery_line_id === null)) {
            return null;
        }

        $deliveryLines = DeliveryLine::query()->whereIn('id', $lines->pluck('delivery_line_id'))->get();
        $deliveryIds = $deliveryLines->pluck('delivery_id')->unique();

        if ($deliveryIds->count() !== 1) {
            return null;
        }

        $delivery = Delivery::query()->find($deliveryIds->first());

        if ($delivery === null || (int) $delivery->created_by !== (int) $invoice->created_by
            || $delivery->delivered_at->toDateString() !== $invoice->invoice_date->toDateString()
            || abs($delivery->created_at->diffInSeconds($invoice->created_at, false)) > self::SAME_TRANSACTION_SECONDS
            || DB::table('consignments')->where('delivery_id', $delivery->id)->exists()) {
            return null;
        }

        $allOnChallan = DeliveryLine::query()->where('delivery_id', $delivery->id)->get();

        foreach ($allOnChallan as $deliveryLine) {
            $billed = $lines->where('delivery_line_id', $deliveryLine->id)->sum(fn ($line) => (float) $line->quantity);

            if ($deliveryLine->sales_order_line_id !== null || abs($billed - (float) $deliveryLine->quantity) > 0.00005) {
                return null;
            }
        }

        $receipts = CustomerReceipt::query()
            ->whereIn('id', CustomerReceiptAllocation::query()->where('sales_invoice_id', $invoice->id)->select('customer_receipt_id'))
            ->whereNull('sales_order_id')
            ->whereNull('voucher_id')
            ->where('created_by', $invoice->created_by)
            ->with('allocations')
            ->get()
            ->filter(fn (CustomerReceipt $receipt) => $receipt->created_at !== null
                && abs($receipt->created_at->diffInSeconds($invoice->created_at, false)) <= self::SAME_TRANSACTION_SECONDS
                && $receipt->allocations->every(fn ($a) => (int) $a->sales_invoice_id === (int) $invoice->id)
                && $receipt->unallocated()->isZero())
            ->values();

        return new self($invoice, $delivery, $receipts);
    }

    /** The money this sale took at the till (its receipts). */
    public function taken(): Money
    {
        return Money::sum($this->receipts->map(fn (CustomerReceipt $r) => $r->amountMoney()));
    }

    /**
     * Is the till shift this sale's cash went into still open, and is it this
     * person's own sale? Then the cashier may void it herself (FINAL-PLAN 3.2:
     * "Staff: own sale while the till shift is open").
     */
    public function voidableByCashier(User $user): bool
    {
        if ((int) $this->invoice->created_by !== (int) $user->id) {
            return false;
        }

        if ($this->receipts->isEmpty()) {
            return false;
        }

        // ⭐ B12 (25 Oct 2026): a bill paid partly by bKash and partly in cash
        // has its till receipt anywhere in the list, not necessarily first.
        return DB::table('pos_shifts')
            ->where('business_id', $this->invoice->business_id)
            ->whereIn('money_account_id', $this->receipts->pluck('money_account_id')->all())
            ->where('status', 'open')
            ->where('opened_at', '<=', $this->invoice->created_at)
            ->exists();
    }
}
