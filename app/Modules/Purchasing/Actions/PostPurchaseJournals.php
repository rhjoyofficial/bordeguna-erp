<?php

namespace App\Modules\Purchasing\Actions;

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
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
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

    public function forReceipt(GoodsReceipt $receipt, User $postedBy): ?Journal
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
            $poLine = $line->purchaseOrderLine;

            $owed = $owed->plus($poLine === null
                ? $value
                : Money::ofProduct((string) $line->quantity_received, (string) $poLine->unit_cost));
        }

        if ($lines === [] || $stocked->isZero()) {
            return null;
        }

        $lines[] = JournalLineDraft::credit($grni->id, $owed, 'Received not invoiced');
        $lines = LedgerRounding::balance($lines, $on, "Rounding on receipt #{$receipt->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Goods received #{$receipt->id}",
            source: $receipt,
        ), $postedBy);
    }

    public function forBill(SupplierBill $bill, User $postedBy): ?Journal
    {
        if ($this->existingJournal($bill) !== null) {
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

        // ⚠ The party is the SUPPLIER, which is what turns one Accounts Payable
        // balance into a subsidiary ledger somebody can act on: "who do we owe"
        // has to be answerable without opening every bill.
        $lines = [
            JournalLineDraft::debit($grni->id, $total, $bill->bill_number),
            JournalLineDraft::credit($payable->id, $total, $bill->bill_number, $bill->supplier),
        ];

        $lines = LedgerRounding::balance($lines, $on, "Rounding on bill {$bill->bill_number}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Supplier bill {$bill->bill_number}",
            source: $bill,
        ), $postedBy);
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
                : Money::ofProduct((string) $line->quantity_returned, (string) $poLine->unit_cost));
        }

        if ($lines === [] || $stocked->isZero()) {
            return null;
        }

        $lines[] = JournalLineDraft::debit($grni->id, $owed, 'Returned to supplier', $return->purchaseOrder?->supplier);
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
    public function forPayment(SupplierPayment $payment, User $postedBy): ?Journal
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

        $lines = [
            JournalLineDraft::debit($payable->id, $total, $payment->reference, $payment->supplier),
            JournalLineDraft::credit($bank->id, $total, $payment->moneyAccount->label(), null, $payment->moneyAccount),
        ];

        $lines = LedgerRounding::balance($lines, $on, "Rounding on payment #{$payment->id}");

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Paid '.$payment->supplier?->name.($payment->reference ? " ({$payment->reference})" : ''),
            source: $payment,
        ), $postedBy);
    }

    public function existingJournal(GoodsReceipt|SupplierBill|PurchaseReturn|SupplierPayment $document): ?Journal
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
