<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\Business;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * ⭐ A full credit note (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.2 and
 * 4.4): the issued invoice is taken back whole, and it is NEVER edited.
 *
 *   1. its one journal is mirrored on the date the correction rules give (its
 *      own date by default): revenue, delivery income, discount and AR undone,
 *      and the cost it released goes back from COGS to GDNI;
 *   2. the challan lines it billed are billable again (quantity_invoiced comes
 *      down), and the order's delivery charge may be billed again (its live
 *      key is cleared; the printed link stays on the line);
 *   3. money an ORDER held and this invoice took (an advance, a gateway
 *      payment) goes back to being held for that order: no journal, the
 *      receipt and the invoice both sat in 1140;
 *   4. a credit note with its own CN number cites the invoice, and the invoice
 *      is marked credited AT ONCE, inside this step: a correcting invoice
 *      created next in the same transaction must already see it gone (the
 *      cost and discount it released, the delivery charge it billed).
 *
 * ⚠ Any other money on the invoice (a receipt typed against it, a return, a
 * write-off, a set-off) is refused by the policy before this runs; Correct
 * moves plain receipts to the new invoice itself (SalesInvoiceCorrector).
 *
 * ⚠ Called only from inside a correction's transaction.
 */
class CreditSalesInvoice
{
    public function __construct(private readonly ReverseJournal $reverseJournal) {}

    /**
     * @return array{journal: ?Journal, credit_note: SalesCreditNote, released: list<array{receipt_id: int, amount: string}>}
     */
    public function credit(SalesInvoice $invoice, User $by, Carbon $on, string $reason, ?ClosedPeriodPass $pass = null): array
    {
        if ($invoice->isReversed()) {
            throw new LogicException("Invoice {$invoice->invoice_number} is already credited.");
        }

        $journal = SalesLedgerCheck::journalOf($invoice);

        if ($journal === null && SalesLedgerCheck::expectsJournal($invoice)) {
            throw new LogicException("Invoice {$invoice->invoice_number} has no single ledger entry to reverse.");
        }

        // The number is claimed inside this transaction (a savepoint retry), so
        // a rollback leaves no used number behind (v2, C13).
        $creditNote = DocumentNumber::claim('credit_note_number', fn () => $this->nextNumber((int) $invoice->business_id, $on), fn (string $number) => SalesCreditNote::create([
            'business_id' => $invoice->business_id,
            'credit_note_number' => $number,
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'kind' => SalesCreditNote::FULL,
            'credit_date' => $on->toDateString(),
            'amount' => (string) $invoice->total,
            'reason' => $reason,
            'created_by' => $by->id,
        ]));

        $memo = "Credit note {$creditNote->credit_note_number} on {$invoice->invoice_number}: {$reason}";
        $reversal = $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, $memo, $pass);
        $creditNote->forceFill(['journal_id' => $reversal?->id])->save();

        foreach (SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->get() as $line) {
            if ($line->delivery_line_id !== null) {
                $deliveryLine = DeliveryLine::query()->whereKey($line->delivery_line_id)->lockForUpdate()->firstOrFail();
                $deliveryLine->forceFill([
                    'quantity_invoiced' => number_format(max(0, (float) $deliveryLine->quantity_invoiced - (float) $line->quantity), 4, '.', ''),
                ])->save();
            }

            if ($line->live_delivery_for_order_id !== null) {
                $line->forceFill(['live_delivery_for_order_id' => null])->save();
            }
        }

        // Money an order held, and this invoice took, is the order's again.
        $released = [];
        $advances = CustomerReceiptAllocation::query()
            ->where('sales_invoice_id', $invoice->id)
            ->whereIn('customer_receipt_id', CustomerReceipt::query()->whereNotNull('sales_order_id')->select('id'))
            ->lockForUpdate()
            ->get();

        foreach ($advances as $allocation) {
            $released[] = ['receipt_id' => (int) $allocation->customer_receipt_id, 'amount' => (string) $allocation->amount];
        }

        CustomerReceiptAllocation::query()->whereKey($advances->modelKeys())->delete();

        $this->mark($invoice, $by, $on, $reason, $reversal);

        return ['journal' => $reversal, 'credit_note' => $creditNote, 'released' => $released];
    }

    public function mark(SalesInvoice $invoice, User $by, Carbon $on, string $reason, ?Journal $reversal): void
    {
        $invoice->forceFill([
            'reversed_on' => $on,
            'reversed_at' => $invoice->reversed_at ?? now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    /** "CN-2026-27-0001": its own series per fiscal year (FINAL-PLAN 4.4). */
    public function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'CN-'.FiscalYear::containing($date, $business)->label().'-';

        return DocumentNumber::next(SalesCreditNote::query(), 'credit_note_number', $prefix);
    }
}
