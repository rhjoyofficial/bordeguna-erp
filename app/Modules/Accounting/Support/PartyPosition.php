<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * ⭐ Both sides of one firm, together (decision D-F, 18 Oct 2026): "We owe them
 * X (open bills) · They owe us Y (open invoices) · Net Z".
 *
 * ⚠ OPEN DOCUMENTS, the same figures a set-off can clear: each bill's and each
 * invoice's outstanding, from the very SQL the payment and receipt forms use
 * (SupplierBill::owingWithOutstanding, SalesInvoice::withOutstandingPaisa), so
 * the card and the set-off form cannot disagree.
 *
 * ⭐ 20 Oct 2026 (owner, the Ayesha case): the NET also counts our advance
 * still with them (the supplier's party balance on 1160), because money paid
 * ahead of a bill is ours until goods or a refund come back for it; the card
 * said "They owe us 39,931" while the truth was 57,331. `settleable` stays
 * open documents only: a set-off clears bills and invoices, never an advance.
 *
 * `net` is signed from our side: positive = they owe us, negative = we owe
 * them. The two ledgers are never merged; this only reads them side by side.
 */
final class PartyPosition
{
    private function __construct(
        public readonly Money $weOwe,
        public readonly Money $theyOwe,
        public readonly int $openBills,
        public readonly int $openInvoices,
        public readonly Money $advance,
        // ⭐ B11 (24 Oct 2026): THEIR advance with us (2150), positive = we hold theirs.
        public readonly Money $theirAdvance,
    ) {}

    public static function of(Supplier $supplier, Customer $customer): self
    {
        $bills = SupplierBill::query()->where('supplier_id', $supplier->id)->owingWithOutstanding()->get();
        $invoices = SalesInvoice::query()->where('customer_id', $customer->id)->unsettled()->withOutstandingPaisa()->get();

        return new self(
            weOwe: Money::ofMinor((int) $bills->sum(fn ($bill) => (int) $bill->outstanding_paisa)),
            theyOwe: Money::ofMinor((int) $invoices->sum(fn ($invoice) => (int) $invoice->outstanding_paisa)),
            openBills: $bills->count(),
            openInvoices: $invoices->count(),
            advance: self::advanceWith($supplier),
            theirAdvance: self::theirAdvance($customer),
        );
    }

    /** The supplier's party balance on 1160 Advances to Suppliers, today (debit = theirs to return). */
    private static function advanceWith(Supplier $supplier): Money
    {
        $account = Account::bySlug('supplier_advances');

        return $account === null ? Money::zero() : Money::ofMinor((int) JournalLine::query()
            ->where('account_id', $account->id)
            ->where('party_type', $supplier->getMorphClass())
            ->where('party_id', $supplier->id)
            ->sum('amount_minor'));
    }

    /** The customer's party balance on 2150 Advances from Customers, today (credit = theirs we hold). */
    private static function theirAdvance(Customer $customer): Money
    {
        $account = Account::bySlug('customer_advances');

        return $account === null ? Money::zero() : Money::ofMinor(-(int) JournalLine::query()
            ->where('account_id', $account->id)
            ->where('party_type', $customer->getMorphClass())
            ->where('party_id', $customer->id)
            ->sum('amount_minor'));
    }

    public function net(): Money
    {
        return Money::ofMinor($this->theyOwe->minor - $this->weOwe->minor + $this->advance->minor - $this->theirAdvance->minor);
    }

    /** The most a set-off could clear today: the smaller side. */
    public function settleable(): Money
    {
        return Money::ofMinor(min($this->theyOwe->minor, $this->weOwe->minor));
    }

    /** @return array<string, mixed> what the supplier and customer pages show */
    public function toArray(): array
    {
        $net = $this->net();

        return [
            'we_owe' => $this->weOwe->format(),
            'they_owe' => $this->theyOwe->format(),
            'open_bills' => $this->openBills,
            'open_invoices' => $this->openInvoices,
            'advance' => ($this->advance->minor < 0 ? '-' : '').$this->advance->absolute()->format(),
            'their_advance' => ($this->theirAdvance->minor < 0 ? '-' : '').$this->theirAdvance->absolute()->format(),
            'net' => $net->absolute()->format(),
            // 'us' = they owe us, 'them' = we owe them, 'even' = nothing either way.
            'net_side' => $net->minor > 0 ? 'us' : ($net->minor < 0 ? 'them' : 'even'),
            'settleable' => $this->settleable()->format(),
        ];
    }
}
