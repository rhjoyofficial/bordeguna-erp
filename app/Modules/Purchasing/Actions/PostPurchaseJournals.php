<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\LedgerRounding;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierBillLine;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\GrniParty;
use App\Modules\Purchasing\Support\ReceiptJournals;
use Illuminate\Support\Carbon;

/**
 * The two purchasing events that move value into the books.
 *
 * **Receiving goods:**   Dr Stock on Hand   Cr Goods Received Not Invoiced
 * **Receiving the bill:** Dr GRNI           Cr Accounts Payable (party = supplier)
 *
 * ⚠⚠ GRNI IS WHAT MAKES THE MONTH CLOSE. Between the lorry arriving and the
 * invoice arriving, the business owns stock it has not been billed for. Without
 * this account that stock sits on the balance sheet with no liability behind it,
 * and the books are wrong by exactly the value of everything in transit - which
 * is a number that grows all month and collapses at random.
 *
 * ⚠ The two entries clear each other: the receipt credits GRNI, the bill debits
 * it. A GRNI balance is therefore a real report - "delivered but not yet
 * invoiced" - rather than a suspense account.
 *
 * ⚠ Lives in Purchasing. The ledger never learns what a goods receipt is.
 */
class PostPurchaseJournals
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * ⭐ Corrections wave 1 (19 Oct 2026): `$pass` is the Owner's one-document
     * closed-month exception, handed down only by the corrections process when
     * a corrected receipt lands in a closed month. Null everywhere else.
     */
    public function forReceipt(GoodsReceipt $receipt, User $postedBy, ?ClosedPeriodPass $pass = null): ?Journal
    {
        if ($this->existingJournal($receipt) !== null) {
            return null;
        }

        $on = Carbon::parse($receipt->received_at ?? $receipt->created_at)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
        $grni = AccountMapping::resolve('inventory.goods_received_not_invoiced', $on);

        $lines = [];
        $stocked = Money::zero();
        $owed = Money::zero();

        // ⚠ Valued from the MOVEMENT the receipt actually wrote, not from the
        // purchase order's price. `goods_receipt_lines.stock_movement_id` exists
        // precisely so the two can never drift: what went into stock and what
        // went into the ledger are the same number by construction.
        foreach ($receipt->lines()->with(['stockMovement', 'purchaseOrderLine'])->get() as $line) {
            $movement = $line->stockMovement;

            if ($movement === null) {
                continue;
            }

            $value = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

            if ($value->isZero()) {
                continue;
            }

            $lines[] = JournalLineDraft::debit($stock->id, $value);
            $stocked = $stocked->plus($value);

            // ⚠⚠ GRNI IS CREDITED WITH WHAT THE SUPPLIER WILL INVOICE, WHICH IS
            // NOT ALWAYS WHAT WENT INTO STOCK.
            //
            // For a product bought in the unit it is stocked in the two are the
            // same number and this changes nothing. For a CONVERTED product they
            // differ by a rounding: stock is 109.0513 litres at a derived
            // 293.4399 each, while the invoice is 100 kilograms at 320.
            //
            // Crediting GRNI with the stock value left a residue there on every
            // converted delivery - 12 paisa on one of the cases in
            // ConvertedReceiptClearsGrniTest - and GRNI is a REPORT, not a
            // suspense: a balance means "received but not yet invoiced". A
            // residue is a small permanent lie about what the business owes.
            //
            // ⚠ LedgerRounding cannot fix this on its own. It closes a gap
            // INSIDE one journal, and both the receipt and the bill journals
            // balance by construction. The two sides have to be computed
            // independently here for the difference to become visible at all -
            // which is exactly the case LedgerRounding's own note anticipated.
            //
            // ⭐ 17 Oct 2026: "what the supplier will invoice" is the ORDER
            // LINE'S STORED TOTAL for the quantity (PurchaseOrderLine::valueOf),
            // so 3,000 units bought for 100,000 credit GRNI with 100,000.00
            // and not with 3,000 x a rounded rate.
            $poLine = $line->purchaseOrderLine;

            $owed = $owed->plus($poLine === null
                ? $value
                : $poLine->valueOf((string) $line->quantity_received));
        }

        if ($lines === [] || $stocked->isZero()) {
            return null;
        }

        // ⭐ 18 Oct 2026 (supplier ledger): the credit names the supplier, so
        // "received from them, not yet billed" is on their ledger (GrniParty).
        $lines[] = JournalLineDraft::credit($grni->id, $owed, 'Received not invoiced', GrniParty::forOrder($receipt->purchaseOrder, $grni->id));
        $lines = LedgerRounding::balance($lines, $on, "Rounding on receipt #{$receipt->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Goods received #{$receipt->id}",
            source: $receipt,
        ), $postedBy, null, $pass);
    }

    /**
     * ⭐ Corrections wave 1 (19 Oct 2026): a purchase order's supplier was a
     * clerical slip, found after the goods arrived but before any bill. The
     * receipt's GRNI credit names the wrong supplier on their ledger, so it is
     * put right the only way the ledger allows: the live receipt journal is
     * reversed on its own date and posted again, line for line, with the GRNI
     * credit naming the order's supplier as it stands now (GrniParty).
     *
     * ⚠ Nothing else changes: the same accounts, the same amounts, the same
     * day. A receipt whose GRNI names no supplier (the untagged path) has
     * nothing to put right and is left alone.
     */
    public function renameReceiptSupplier(GoodsReceipt $receipt, User $by, string $reason): ?Journal
    {
        $journal = ReceiptJournals::live($receipt);

        if ($journal === null) {
            return null;
        }

        $journal->load('lines');
        $grni = AccountMapping::resolve('inventory.goods_received_not_invoiced', Carbon::parse($journal->journal_date));
        $tagged = $journal->lines->contains(fn ($line) => (int) $line->account_id === (int) $grni->id && $line->party_id !== null);

        if (! $tagged) {
            return null;
        }

        $supplier = GrniParty::forOrder($receipt->purchaseOrder()->with('supplier')->first(), $grni->id);
        $on = Carbon::parse($journal->journal_date)->startOfDay();

        app(ReverseJournal::class)->handle($journal, $by, $on, "Supplier corrected on Goods received #{$receipt->id}: {$reason}");

        $lines = $journal->lines->map(function ($line) use ($grni, $supplier) {
            $amount = Money::ofMinor(abs((int) $line->amount_minor));
            $isGrni = (int) $line->account_id === (int) $grni->id;

            return (int) $line->amount_minor >= 0
                ? JournalLineDraft::debit($line->account_id, $amount, $line->memo)
                : JournalLineDraft::credit($line->account_id, $amount, $line->memo, $isGrni ? $supplier : null);
        })->all();

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Goods received #{$receipt->id} (supplier corrected)",
            source: $receipt,
        ), $by);
    }

    public function forBill(SupplierBill $bill, User $postedBy, ?ClosedPeriodPass $pass = null): ?Journal
    {
        // ⚠⚠ An opening bill has no lines: laid out here its whole total would
        // post as a price variance. Its one journal is RecordOpeningBalanceDocument's.
        if ($bill->is_opening || $this->existingJournal($bill) !== null) {
            return null;
        }

        $on = Carbon::parse($bill->bill_date)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $grni = AccountMapping::resolve('inventory.goods_received_not_invoiced', $on);
        $payable = AccountMapping::resolve('purchasing.accounts_payable', $on);

        $total = Money::ofTaka((string) $bill->total);

        if ($total->isZero()) {
            return null;
        }

        // ⚠⚠ D-SB-2. GRNI IS RELIEVED AT THE RECEIVED VALUE, NOT AT THE BILL
        // TOTAL. The receipt credited GRNI with billed quantity times the
        // PURCHASE ORDER's price, so debiting it with the invoice total instead
        // left the difference stranded there permanently, and the trial balance
        // still tied while it happened. Same basis on both sides, so GRNI clears
        // exactly whatever the supplier invoiced.
        //
        // ⚠ Bor de Guna is FIFO. The order carries an AGREED PRICE, not a
        // standard cost, and nothing here revalues a batch or rewrites cost of
        // goods sold. The variance is the gap between what was agreed and what
        // was invoiced, and it is reported rather than absorbed.
        // ⭐ VAT (plan D7). Claimable VAT is not a cost: it comes out of what is
        // compared with the received value, and goes to Input VAT Receivable on
        // a line of its own (C-VAT-6: never netted against output VAT in the
        // ledger). VAT that may NOT be claimed stays in `total`, so it lands in
        // the variance and therefore in the cost of the goods. Zero today.
        $recoverableTax = $bill->recoverableTax();

        // ⭐ Ruling P5 (19 Oct 2026): GRNI is relieved PER LINE, each naming
        // the order and the challan it clears, so the supplier ledger shows
        // which delivery a bill paid for. The sum is the same figure as before.
        $receivedLines = $this->receivedLinesOf($bill);
        $received = Money::sum(array_column($receivedLines, 'value'));

        // ⭐ Ruling P5: non-PO lines debit their own expense head, at the
        // amount the supplier billed; they are no part of the price variance.
        $expenseLines = $bill->lines()->where('line_kind', SupplierBillLine::KIND_EXPENSE)->with('expenseAccount')->get();
        $expensed = Money::sum($expenseLines->map(fn (SupplierBillLine $line) => Money::ofTaka((string) $line->line_total)));

        $variance = Money::ofMinor($total->minor - $recoverableTax->minor - $received->minor - $expensed->minor);

        // ⚠ The party is the SUPPLIER, which is what turns one Accounts Payable
        // balance into a subsidiary ledger somebody can act on: "who do we owe"
        // has to be answerable without opening every bill.
        $lines = [];

        // ⚠ Omitted when nothing on the bill went into stock (a service bill):
        // GRNI holds nothing for it, and a zero line is refused by the ledger.
        if (! $received->isZero()) {
            // ⭐ 18 Oct 2026: relieves the supplier's own GRNI (GrniParty).
            $party = GrniParty::forBill($bill, $grni->id);

            foreach ($receivedLines as $row) {
                if (! $row['value']->isZero()) {
                    $lines[] = JournalLineDraft::debit($grni->id, $row['value'], $row['memo'], $party);
                }
            }
        }

        foreach ($expenseLines as $line) {
            $lines[] = JournalLineDraft::debit($line->expense_account_id, Money::ofTaka((string) $line->line_total), "{$bill->bill_number}: ".($line->description ?: 'service'));
        }

        // ⚠ Omitted entirely when the price matched. A zero-value line is noise
        // on a report somebody has to read, and the commonest case is agreement.
        if (! $variance->isZero()) {
            $lines[] = JournalLineDraft::signed(
                AccountMapping::resolve('purchasing.price_variance', $on)->id,
                $variance,
                "Price variance on {$bill->bill_number}",
            );
        }

        if (! $recoverableTax->isZero()) {
            $inputVat = AccountMapping::resolve('purchasing.input_vat', $on);
            $lines[] = JournalLineDraft::debit($inputVat->id, $recoverableTax, 'VAT on '.$bill->bill_number, $bill->supplier);
        }

        $lines[] = JournalLineDraft::credit($payable->id, $total, $bill->bill_number, $bill->supplier);

        $lines = LedgerRounding::balance($lines, $on, "Rounding on bill {$bill->bill_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Supplier bill {$bill->bill_number}",
            source: $bill,
        ), $postedBy, pass: $pass);
    }

    /**
     * Goods going back to the supplier.
     *
     *   Dr  Goods Received Not Invoiced   Cr  Stock on Hand
     *
     * ⚠⚠ THE CONTRA IS GRNI, NOT ACCOUNTS PAYABLE, and the reason is symmetry:
     * a return is the reversal of a RECEIPT, and the receipt credited GRNI. The
     * two entries cancel exactly, which is what makes "delivered but not yet
     * invoiced" a figure that can be trusted.
     *
     * ⚠ If the bill has already arrived, the supplier owes a credit note, and
     * moving that from GRNI to Accounts Payable is the credit note's own entry -
     * a document that does not exist yet. Recorded in the coverage matrix as a
     * named gap rather than guessed at here: crediting AP for goods that were
     * never invoiced would put a debit balance against a supplier who has not
     * billed us.
     *
     * ⚠ Valued from the reversing MOVEMENTS via `purchase_return_lines.
     * stock_movement_id`, the column added in slice A2 precisely so a return
     * could find its own paperwork. I1 made those movements reverse the specific
     * receipts this supplier delivered, so the value here is what they were
     * received at rather than a FIFO cost from somewhere else.
     */
    public function forReturn(PurchaseReturn $return, User $postedBy): ?Journal
    {
        if ($this->existingJournal($return) !== null) {
            return null;
        }

        $on = Carbon::parse($return->returned_at ?? $return->created_at)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $stock = AccountMapping::resolve('inventory.stock_on_hand', $on);
        $grni = AccountMapping::resolve('inventory.goods_received_not_invoiced', $on);

        $lines = [];
        $stocked = Money::zero();
        $owed = Money::zero();

        foreach ($return->lines()->with(['stockMovement', 'purchaseOrderLine'])->get() as $line) {
            $movement = $line->stockMovement;

            if ($movement === null) {
                continue;
            }

            $value = Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

            if ($value->isZero()) {
                continue;
            }

            $lines[] = JournalLineDraft::credit($stock->id, $value);
            $stocked = $stocked->plus($value);

            // ⚠⚠ THE MIRROR OF `forReceipt`, AND IT HAS TO BE. The receipt
            // credits GRNI with what the supplier will invoice; if the return
            // debited GRNI with the STOCK value instead, receiving 100 kg of a
            // converted product and sending all 100 kg back would leave in GRNI
            // exactly the paisa-level residue the receipt side was rewritten to
            // eliminate - in an account that is a report, not a suspense.
            //
            // ⚠ For a product bought in the unit it is stocked in the two are
            // the same number, so nothing changes for anything else.
            $poLine = $line->purchaseOrderLine;

            $owed = $owed->plus($poLine === null
                ? $value
                : $poLine->valueOf((string) $line->quantity_returned));
        }

        if ($lines === [] || $stocked->isZero()) {
            return null;
        }

        // ⚠ 18 Oct 2026: an order still on the untagged path stays untagged
        // here too, so its receipt and its return clear each other (GrniParty).
        $lines[] = JournalLineDraft::debit($grni->id, $owed, 'Returned to supplier', GrniParty::forOrder($return->purchaseOrder, $grni->id));
        $lines = LedgerRounding::balance($lines, $on, "Rounding on return #{$return->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Purchase return #{$return->id}",
            source: $return,
        ), $postedBy);
    }

    /**
     * Paying the supplier.
     *
     *   Dr  Accounts Payable (party = supplier)   Cr  the money account
     *
     * ⚠ The credit account comes from `money_accounts.account_id`, not a mapping
     * key - a bank account already knows what it is. Refused outright if that is
     * unset, because posting the credit somewhere plausible is how a cash book
     * goes wrong in a way nobody finds.
     */
    public function forPayment(SupplierPayment $payment, User $postedBy, ?ClosedPeriodPass $pass = null): ?Journal
    {
        if ($this->existingJournal($payment) !== null) {
            return null;
        }

        $on = Carbon::parse($payment->paid_on)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        if ($payment->moneyAccount?->account_id === null) {
            throw MappingNotFound::for("money account [{$payment->moneyAccount?->name}]", $on);
        }

        $payable = AccountMapping::resolve('purchasing.accounts_payable', $on);
        $bank = Account::findOrFail($payment->moneyAccount->account_id);
        $total = Money::ofTaka((string) $payment->amount);

        if ($total->isZero()) {
            return null;
        }

        // ⭐ Supplier advances (ruling P2, 18 Oct 2026): what the payer
        // confirmed beyond the bills is an advance, on THIS journal, because it
        // is one remittance and the bank statement shows it once.
        $advance = SupplierAdvance::query()->where('supplier_payment_id', $payment->id)->where('kind', 'overpayment')->first();

        // Finance decision C4: the payable is settled in full, the bank pays
        // the net, and the tax held back is owed to NBR.
        $lines = [
            JournalLineDraft::debit($payable->id, $total, $payment->reference, $payment->supplier),
            JournalLineDraft::credit($bank->id, $payment->netPaid()->plus($advance?->netPaid() ?? Money::zero()), $payment->moneyAccount->label(), null, $payment->moneyAccount),
        ];

        if ($advance !== null) {
            $lines[] = JournalLineDraft::debit(Account::bySlug('supplier_advances')->id, $advance->money(), "Advance {$advance->number}", $payment->supplier);
        }

        foreach (['tds' => 'tds_payable', 'vds' => 'vds_payable'] as $kind => $slug) {
            $held = Money::ofTaka((string) $payment->{"{$kind}_amount"})
                ->plus(Money::ofTaka((string) ($advance?->{"{$kind}_amount"} ?? '0')));

            if (! $held->isZero()) {
                $lines[] = JournalLineDraft::credit(Account::bySlug($slug)->id, $held, strtoupper($kind).' held from '.$payment->supplier?->name, $payment->supplier);
            }
        }

        $lines = LedgerRounding::balance($lines, $on, "Rounding on payment #{$payment->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Paid '.$payment->supplier?->name.($payment->reference ? " ({$payment->reference})" : ''),
            source: $payment,
        ), $postedBy, pass: $pass);
    }

    /**
     * The supplier's credit note for goods returned after the bill arrived.
     *
     *   Dr  Accounts Payable (party = supplier)   Cr  Goods Received Not Invoiced
     *
     * ⚠ The mirror of the bill, at the credit's value. The late return left
     * GRNI with a DEBIT balance - the supplier owed us for goods sent back
     * after billing - and this is the entry that moves that claim onto the
     * supplier's payable balance, where the next payment can see it.
     */
    public function forCreditNote(SupplierCreditNote $creditNote, User $postedBy, ?ClosedPeriodPass $pass = null): ?Journal
    {
        if ($this->existingJournal($creditNote) !== null) {
            return null;
        }

        $on = Carbon::parse($creditNote->credit_date)->startOfDay();

        if (AccountMapping::forKeyOn('inventory.stock_on_hand', $on) === null) {
            return null;
        }

        $grni = AccountMapping::resolve('inventory.goods_received_not_invoiced', $on);
        $payable = AccountMapping::resolve('purchasing.accounts_payable', $on);

        $total = Money::ofTaka((string) $creditNote->total);

        if ($total->isZero()) {
            return null;
        }

        // ⭐ VAT the supplier gives back is un-claimed: out of Input VAT
        // Receivable, and only the goods part relieves GRNI. Zero today.
        $tax = Money::ofTaka((string) $creditNote->tax_total);
        $goods = Money::ofMinor($total->minor - $tax->minor);

        $lines = [
            JournalLineDraft::debit($payable->id, $total, $creditNote->credit_note_number, $creditNote->supplier),
        ];

        if (! $goods->isZero()) {
            $lines[] = JournalLineDraft::credit($grni->id, $goods, $creditNote->credit_note_number, GrniParty::forCreditNote($creditNote, $grni->id));
        }

        if (! $tax->isZero()) {
            $inputVat = AccountMapping::resolve('purchasing.input_vat', $on);
            $lines[] = JournalLineDraft::credit($inputVat->id, $tax, 'VAT on '.$creditNote->credit_note_number, $creditNote->supplier);
        }

        $lines = LedgerRounding::balance($lines, $on, "Rounding on credit note {$creditNote->credit_note_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Supplier credit note {$creditNote->credit_note_number}",
            source: $creditNote,
        ), $postedBy, pass: $pass);
    }

    /**
     * What the goods on this bill were RECEIVED at, which is what GRNI holds.
     *
     * ⚠⚠ Valued from the PURCHASE ORDER LINE's stored total for the billed
     * quantity (PurchaseOrderLine::valueOf), never from the stock movement.
     * `forReceipt()` credits GRNI on exactly this basis and documents why: for
     * a converted product, stock is valued at a derived per-unit cost while
     * the invoice is per purchase unit, and crediting GRNI with the stock
     * value left a residue on every converted delivery. The two sides have to
     * agree or the whole point of clearing GRNI is lost.
     *
     * ⭐ 17 Oct 2026: the variance is therefore the bill line's own stored
     * total against the order line's stored total, two typed figures, with no
     * rounded rate between them. PPV policy unchanged: the gap is reported,
     * no batch is revalued.
     *
     * ⚠ A line with no purchase order line behind it contributes nothing, so a
     * bill that reaches no goods relieves no GRNI and its whole value becomes
     * variance. D-SB-3 keeps such lines off bills in the first place.
     *
     * ⚠⚠ So does a line whose receipt put nothing into stock - a service, or
     * anything else received with no stock movement. `forReceipt()` skips those
     * lines and never credits GRNI for them, so relieving GRNI here left a debit
     * stranded there on every service bill. Their value is variance instead,
     * which is an expense head. Decided from what the receipt actually wrote,
     * not from the product's type today, which can have changed since.
     */
    /**
     * @return list<array{value: Money, memo: string}>
     */
    private function receivedLinesOf(SupplierBill $bill): array
    {
        $rows = [];

        foreach ($bill->lines()->with(['purchaseOrderLine.purchaseOrder:id,po_number', 'purchaseOrderLine.product:id,name', 'goodsReceiptLine.goodsReceipt:id,supplier_challan_number'])->get() as $line) {
            $poLine = $line->purchaseOrderLine;

            if ($poLine === null) {
                continue;
            }

            $wentIntoStock = GoodsReceiptLine::query()
                ->where('purchase_order_line_id', $poLine->id)
                ->whereNotNull('stock_movement_id')
                ->exists();

            if (! $wentIntoStock) {
                continue;
            }

            // ⭐ Ruling P5: which order, and which challan, this line clears.
            $challan = $line->goodsReceiptLine?->goodsReceipt?->supplier_challan_number;
            $memo = implode(' ', array_filter([
                $bill->bill_number,
                $poLine->purchaseOrder?->po_number,
                $challan ? "challan {$challan}" : null,
                $poLine->product?->name,
            ]));

            $rows[] = ['value' => $poLine->valueOf((string) $line->quantity), 'memo' => mb_substr($memo, 0, 250)];
        }

        return $rows;
    }

    public function existingJournal(GoodsReceipt|SupplierBill|PurchaseReturn|SupplierPayment|SupplierCreditNote $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
