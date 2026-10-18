<?php

namespace App\Modules\Accounting\Support;

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
 * the card and the set-off form cannot disagree. An unallocated advance is the
 * statements' business, not this card's.
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
        );
    }

    public function net(): Money
    {
        return Money::ofMinor($this->theyOwe->minor - $this->weOwe->minor);
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
            'net' => $net->absolute()->format(),
            // 'us' = they owe us, 'them' = we owe them, 'even' = nothing either way.
            'net_side' => $net->minor > 0 ? 'us' : ($net->minor < 0 ? 'them' : 'even'),
            'settleable' => $this->settleable()->format(),
        ];
    }
}
