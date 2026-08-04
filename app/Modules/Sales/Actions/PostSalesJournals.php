<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;

/**
 * The sales events that move value into the books.
 *
 * **Goods leaving:** Dr Goods Delivered Not Invoiced   Cr Stock on Hand
 *
 * ⚠⚠ GDNI IS THE MIRROR OF GRNI, AND IT IS WHAT MAKES THE MONTH CLOSE. Between
 * the goods leaving on a challan and the invoice being raised, the business no
 * longer holds the stock but has not yet earned anything against it. Without
 * this account the cost lands in the month the lorry left and the revenue lands
 * in the month the invoice went out, and every gross margin is drawn from two
 * different periods - a fake loss one month, a fake profit the next.
 *
 * ⚠ Joy's distributor flow is "challan today, invoice at month end", so that
 * split is the NORMAL case here, not an edge case.
 *
 * ⚠ A GDNI balance is a real report - "out on challan, not yet billed" - rather
 * than a suspense account. For a counter sale it is debited and credited inside
 * the same transaction and nets to zero instantly.
 *
 * ⚠ Lives in Sales. The ledger never learns what a delivery is.
 */
class PostSalesJournals
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * ⚠⚠ Valued from the MOVEMENTS the delivery actually created, never from a
     * selling price and never from an average cost. One line at FIFO consumes
     * several batches at several costs, and the sum of those layers IS the cost
     * of goods sold. Anything else is a second opinion about what the stock
     * cost - the same rule that values a goods receipt from its movement rather
     * than from the purchase order.
     */
    public function forDelivery(Delivery $delivery, User $postedBy): ?Journal
    {
        if ($this->existingJournal($delivery) !== null) {
            return null;
        }

        $on = Carbon::parse($delivery->delivered_at)->startOfDay();

        // ⚠⚠ THE ANCHOR. A business whose ledger is not configured must still be
        // able to send goods to a customer. Absent means Sales posts nothing and
        // behaves exactly as it did before Accounting existed.
        if (AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return null;
        }

        // ⚠ Resolved, not checked: if sales posting is switched on but one of
        // its accounts is unmapped, that is a defect and the event must fail
        // loudly rather than post a half-entry.
        $gdni = AccountMapping::resolve('sales.goods_delivered_not_invoiced', $on);
        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);

        $lines = [];
        $total = Money::zero();

        $delivery->loadMissing('lines.movements');

        foreach ($delivery->lines as $line) {
            foreach ($line->movements as $movement) {
                $value = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

                if ($value->isZero()) {
                    continue;
                }

                $lines[] = JournalLineDraft::credit($stock->id, $value);
                $total = $total->plus($value);
            }
        }

        // ⚠ Zero-value stock is real - a free sample from a batch received at no
        // cost - and it is not an error. There is simply nothing to post.
        if ($lines === [] || $total->isZero()) {
            return null;
        }

        $lines[] = JournalLineDraft::debit(
            $gdni->id,
            $total,
            'Delivered not invoiced',
            $delivery->customer,
        );

        $lines = LedgerRounding::balance($lines, $on, "Rounding on delivery {$delivery->delivery_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Delivery {$delivery->delivery_number}",
            source: $delivery,
        ), $postedBy);
    }

    /**
     * Billing the customer.
     *
     * ```
     * Dr  Cost of Goods Sold                    cost released from the challan
     *     Cr  Goods Delivered Not Invoiced
     *
     * Dr  Accounts Receivable (party = customer)   what they owe
     * Dr  Discounts Allowed                        invoice-level discount
     *     Cr  Sales Revenue                        subtotal, net of line discounts
     * ```
     *
     * ⚠⚠ ONE JOURNAL, both halves. The revenue and the cost of that revenue
     * belong to the same event, and splitting them invites a month where one
     * posted and the other did not.
     *
     * ⚠ Accounts Receivable is debited even when the customer pays cash at the
     * counter. The receipt then credits it in the same transaction and the
     * balance nets to zero instantly. That is what makes retail and credit the
     * same engine instead of two - see SALES-PHASE1-DESIGN.md §2.3.
     *
     * ⚠ A LINE discount never appears here. It reduced the line, so Sales
     * Revenue is credited net and reads as the real figure. Only an
     * invoice-level discount is contra-revenue.
     */
    public function forInvoice(SalesInvoice $invoice, User $postedBy): ?Journal
    {
        if ($this->existingJournal($invoice) !== null) {
            return null;
        }

        $on = Carbon::parse($invoice->invoice_date)->startOfDay();

        if (AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return null;
        }

        $revenue = AccountMapping::resolve('sales.revenue', $on);
        $receivable = AccountMapping::resolve('sales.accounts_receivable', $on);

        $invoice->loadMissing('lines', 'customer');

        $subtotal = Money::ofTaka((string) $invoice->subtotal);
        $discount = Money::ofTaka((string) $invoice->invoice_discount);
        $total = Money::ofTaka((string) $invoice->total);
        $cost = $invoice->costReleased();

        $lines = [];

        // ⚠ The cost half only exists when goods were actually delivered. A pure
        // service invoice has nothing in GDNI to release.
        if (! $cost->isZero()) {
            $cogs = AccountMapping::resolve('sales.cost_of_goods_sold', $on);
            $gdni = AccountMapping::resolve('sales.goods_delivered_not_invoiced', $on);

            $lines[] = JournalLineDraft::debit($cogs->id, $cost, 'Cost of goods sold');
            $lines[] = JournalLineDraft::credit($gdni->id, $cost, 'Released on '.$invoice->invoice_number, $invoice->customer);
        }

        if (! $total->isZero()) {
            $lines[] = JournalLineDraft::debit($receivable->id, $total, $invoice->invoice_number, $invoice->customer);
        }

        if (! $discount->isZero()) {
            $discounts = AccountMapping::resolve('sales.discounts_allowed', $on);
            $lines[] = JournalLineDraft::debit($discounts->id, $discount, 'Discount on '.$invoice->invoice_number, $invoice->customer);
        }

        if (! $subtotal->isZero()) {
            $lines[] = JournalLineDraft::credit($revenue->id, $subtotal, $invoice->invoice_number);
        }

        if ($lines === []) {
            return null;
        }

        $lines = LedgerRounding::balance($lines, $on, "Rounding on invoice {$invoice->invoice_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Invoice {$invoice->invoice_number}",
            source: $invoice,
        ), $postedBy);
    }

    /**
     * The customer paying.
     *
     *   Dr  the money account   Cr  Accounts Receivable (party = customer)
     *
     * ⚠ The debit account comes from `money_accounts.account_id`, not a mapping
     * key - a bank account already knows what it is. Refused outright if that is
     * unset, because posting the debit somewhere plausible is how a cash book
     * goes wrong in a way nobody finds. Identical to the supplier side.
     */
    public function forReceipt(CustomerReceipt $receipt, User $postedBy): ?Journal
    {
        if ($this->existingJournal($receipt) !== null) {
            return null;
        }

        $on = Carbon::parse($receipt->received_on)->startOfDay();

        if (AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return null;
        }

        if ($receipt->moneyAccount?->account_id === null) {
            throw MappingNotFound::for("money account [{$receipt->moneyAccount?->name}]", $on);
        }

        $receivable = AccountMapping::resolve('sales.accounts_receivable', $on);
        $bank = Account::findOrFail($receipt->moneyAccount->account_id);
        $total = Money::ofTaka((string) $receipt->amount);

        if ($total->isZero()) {
            return null;
        }

        $lines = [
            JournalLineDraft::debit($bank->id, $total, $receipt->moneyAccount->label(), null, $receipt->moneyAccount),
            JournalLineDraft::credit($receivable->id, $total, $receipt->reference, $receipt->customer),
        ];

        $lines = LedgerRounding::balance($lines, $on, "Rounding on receipt #{$receipt->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Received from '.$receipt->customer?->name.($receipt->reference ? " ({$receipt->reference})" : ''),
            source: $receipt,
        ), $postedBy);
    }

    public function existingJournal(Delivery|SalesInvoice|CustomerReceipt $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
