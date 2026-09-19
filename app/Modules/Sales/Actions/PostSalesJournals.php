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
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
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

        // ⭐ Goods credit sales revenue; a delivery charge credits 4300 Delivery
        // Charges Income, its own line on the profit and loss (Joy, 13 Sep).
        // ⭐ NET OF VAT (VAT plan D2/D3). Identical to the gross figures while
        // VAT is off; with VAT on, the VAT goes to Output VAT Payable below and
        // revenue, delivery income and discounts are what is left.
        $subtotal = $invoice->goodsRevenueExTax();
        $delivery = $invoice->deliveryExTax();
        $discount = $invoice->discountExTax();
        $tax = $invoice->taxTotal();
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

        // ⚠ Resolved only when there is a charge, so a business whose website
        // was never configured still invoices counter sales; one that bills a
        // charge with no mapping is refused with the mapping named.
        if (! $delivery->isZero()) {
            $deliveryIncome = AccountMapping::resolve('sales.delivery_income', $on);
            $lines[] = JournalLineDraft::credit($deliveryIncome->id, $delivery, 'Delivery on '.$invoice->invoice_number);
        }

        // ⚠ Its own account, never netted against input VAT in the ledger
        // (C-VAT-6). The net is the VAT return's arithmetic, not a posting.
        if (! $tax->isZero()) {
            $outputVat = AccountMapping::resolve('sales.output_vat', $on);
            $lines[] = JournalLineDraft::credit($outputVat->id, $tax, 'VAT on '.$invoice->invoice_number, $invoice->customer);
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

    /**
     * Goods coming back (SALES-RETURNS-ARCHITECTURE.md §4), one journal:
     *
     * ```
     * Dr  Stock on Hand                    layer cost of what went back on the shelf
     * Dr  the damaged-goods loss account   layer cost of what was destroyed
     * Dr  Sales Returns (4200)             gross credit at the invoiced prices
     *     Cr  GDNI (party = customer)      pro-rata relief of the unbilled portion
     *     Cr  Cost of Goods Sold           the balancing cost figure (may flip to Dr)
     *     Cr  Discounts Allowed            clawed-back share of invoice discounts
     *     Cr  AR (party = customer)        the net the customer is credited
     * ```
     *
     * ⚠ 4200 posts GROSS, never netted into 4100 - "we sold 100,000 and took
     * back 8,000" must be visible on the face of the P&L, because the return
     * rate is a real operational signal for a food business (C-SR-5).
     *
     * ⚠ The write-off side reuses the damaged-goods adjustment mapping
     * (C-SR-7): destroyed-because-returned and destroyed-on-the-shelf read as
     * the same kind of loss.
     */
    public function forSalesReturn(SalesReturn $salesReturn, User $postedBy): ?Journal
    {
        if ($this->existingJournal($salesReturn) !== null) {
            return null;
        }

        $on = Carbon::parse($salesReturn->returned_at)->startOfDay();

        if (AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return null;
        }

        $salesReturn->loadMissing('lines', 'customer');

        $costRestored = Money::zero();
        $costWrittenOff = Money::zero();
        $gdniRelief = Money::zero();
        $cogsRelief = Money::zero();
        $creditGross = Money::zero();
        $creditDiscount = Money::zero();
        $creditNet = Money::zero();

        foreach ($salesReturn->lines as $line) {
            $costRestored = $costRestored->plus(Money::ofTaka((string) $line->cost_restored));
            $costWrittenOff = $costWrittenOff->plus(Money::ofTaka((string) $line->cost_written_off));
            $gdniRelief = $gdniRelief->plus(Money::ofTaka((string) $line->gdni_relief));
            $cogsRelief = $cogsRelief->plus(Money::ofTaka((string) $line->cogs_relief));
            $creditGross = $creditGross->plus(Money::ofTaka((string) $line->credit_gross));
            $creditDiscount = $creditDiscount->plus(Money::ofTaka((string) $line->credit_discount));
            $creditNet = $creditNet->plus(Money::ofTaka((string) $line->credit_net));
        }

        $lines = [];

        if (! $costRestored->isZero()) {
            $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
            $lines[] = JournalLineDraft::debit($stock->id, $costRestored, 'Returned to stock on '.$salesReturn->return_number);
        }

        if (! $costWrittenOff->isZero()) {
            $loss = AccountMapping::resolve('inventory.adjustment.damaged', $on, 'inventory.adjustment.default');
            $lines[] = JournalLineDraft::debit($loss->id, $costWrittenOff, 'Written off on '.$salesReturn->return_number);
        }

        if (! $creditGross->isZero()) {
            $returns = AccountMapping::resolve('sales.sales_returns', $on);
            $lines[] = JournalLineDraft::debit($returns->id, $creditGross, $salesReturn->return_number);
        }

        if (! $gdniRelief->isZero()) {
            $gdni = AccountMapping::resolve('sales.goods_delivered_not_invoiced', $on);
            $lines[] = JournalLineDraft::credit($gdni->id, $gdniRelief, 'Returned before billing', $salesReturn->customer);
        }

        // ⚠ The balancing figure may be negative - returned layers cheaper
        // than the line average - and a negative credit is a debit, stated
        // as one rather than smuggled through as a sign.
        if (! $cogsRelief->isZero()) {
            $cogs = AccountMapping::resolve('sales.cost_of_goods_sold', $on);
            $lines[] = $cogsRelief->minor > 0
                ? JournalLineDraft::credit($cogs->id, $cogsRelief, 'Cost released back on '.$salesReturn->return_number)
                : JournalLineDraft::debit($cogs->id, $cogsRelief->absolute(), 'Cost trued up on '.$salesReturn->return_number);
        }

        if (! $creditDiscount->isZero()) {
            $discounts = AccountMapping::resolve('sales.discounts_allowed', $on);
            $lines[] = JournalLineDraft::credit($discounts->id, $creditDiscount, 'Discount clawed back on '.$salesReturn->return_number);
        }

        if (! $creditNet->isZero()) {
            $receivable = AccountMapping::resolve('sales.accounts_receivable', $on);
            $lines[] = JournalLineDraft::credit($receivable->id, $creditNet, $salesReturn->return_number, $salesReturn->customer);
        }

        // A fully-uninvoiced return of zero-cost goods has nothing to post,
        // and that is not an error - free samples came back.
        if ($lines === []) {
            return null;
        }

        $lines = LedgerRounding::balance($lines, $on, "Rounding on return {$salesReturn->return_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Sales return {$salesReturn->return_number}",
            source: $salesReturn,
        ), $postedBy);
    }

    /**
     * Money going back (C-SR-6): Dr AR (party = customer) / Cr the money
     * account - the exact reverse of a receipt, consuming the credit the
     * return created.
     */
    public function forCustomerRefund(CustomerRefund $refund, User $postedBy): ?Journal
    {
        if ($this->existingJournal($refund) !== null) {
            return null;
        }

        $on = Carbon::parse($refund->refunded_on)->startOfDay();

        if (AccountMapping::forKeyOn('sales.revenue', $on) === null) {
            return null;
        }

        if ($refund->moneyAccount?->account_id === null) {
            throw MappingNotFound::for("money account [{$refund->moneyAccount?->name}]", $on);
        }

        $receivable = AccountMapping::resolve('sales.accounts_receivable', $on);
        $bank = Account::findOrFail($refund->moneyAccount->account_id);
        $total = Money::ofTaka((string) $refund->amount);

        if ($total->isZero()) {
            return null;
        }

        $lines = [
            JournalLineDraft::debit($receivable->id, $total, $refund->reference ?? 'Refund', $refund->customer),
            JournalLineDraft::credit($bank->id, $total, $refund->moneyAccount->label(), null, $refund->moneyAccount),
        ];

        $lines = LedgerRounding::balance($lines, $on, "Rounding on refund #{$refund->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Refunded to '.$refund->customer?->name.($refund->reference ? " ({$refund->reference})" : ''),
            source: $refund,
        ), $postedBy);
    }

    public function existingJournal(Delivery|SalesInvoice|CustomerReceipt|SalesReturn|CustomerRefund $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
