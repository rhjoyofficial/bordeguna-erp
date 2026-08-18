<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a supplier's invoice.
 *
 * ⚠⚠ This is the document Accounts Payable is a ledger of, and it did not
 * exist. Goods arrive in one month and the bill arrives in another; the gap
 * between what was received and what has been invoiced is Goods Received Not
 * Invoiced, and it is the accrual that makes accrual accounting mean anything.
 *
 * ✅ Phase 3: it now posts. Dr Goods Received Not Invoiced, Cr Accounts Payable
 * with the supplier as the line's party - which is what turns one payables
 * balance into a ledger somebody can act on. The receipt credited GRNI and this
 * debits it, so the two clear each other and a GRNI balance is a real report:
 * delivered but not yet invoiced.
 */
class RecordSupplierBill
{
    public function __construct(private readonly PostPurchaseJournals $posting) {}

    /**
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string, unitCost: string}>  $lines
     */
    public function handle(
        Supplier $supplier,
        string $billNumber,
        mixed $billDate,
        array $lines,
        User $createdBy,
        ?PurchaseOrder $purchaseOrder = null,
        mixed $dueDate = null,
        ?string $note = null,
    ): SupplierBill {
        $date = Carbon::parse($billDate);

        // The generalised period lock. A bill dated into a closed month would
        // change a figure somebody has already reported.
        AccountingPeriod::assertOpenFor($date);

        // ⚠⚠ The oldest control in accounts payable, checked before anything is
        // written: the same invoice number from the same supplier twice is how a
        // business pays for one delivery twice. The database enforces it too;
        // this exists to fail with a sentence rather than a constraint violation.
        $duplicate = SupplierBill::where('supplier_id', $supplier->id)
            ->where('bill_number', $billNumber)
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException(
                "Bill {$billNumber} has already been recorded for {$supplier->name}."
            );
        }

        return DB::transaction(function () use ($supplier, $billNumber, $date, $lines, $createdBy, $purchaseOrder, $dueDate, $note) {
            $bill = SupplierBill::create([
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $purchaseOrder?->id,
                'bill_number' => $billNumber,
                'bill_date' => $date,
                'due_date' => $dueDate ? Carbon::parse($dueDate) : null,
                'note' => $note,
                'created_by' => $createdBy->id,
                'total' => 0,
            ]);

            /** @var list<Money> $lineTotals */
            $lineTotals = [];

            foreach ($lines as $entry) {
                // ⚠ Re-read under a lock, the same lesson as I3: two people
                // billing the same delivery from stale copies would each pass
                // the check below and the second would overwrite the first.
                $line = PurchaseOrderLine::whereKey($entry['line']->id)->lockForUpdate()->firstOrFail();

                // ⚠⚠ D-SB-1. ONE BILL, ONE PURCHASE ORDER. The bill carries a
                // single `purchase_order_id`, and nothing checked that the lines
                // belonged to it, so a bill could name one order and invoice
                // another order's goods. The header then said one thing and the
                // lines did another, and the three-way match silently guarded
                // the wrong order.
                if ($purchaseOrder !== null && $line->purchase_order_id !== $purchaseOrder->id) {
                    throw new InvalidArgumentException(
                        "Line #{$line->id} belongs to a different purchase order. A bill covers exactly one order."
                    );
                }

                // ⚠ Integer-scaled, not float. Quantities are decimal(15,4), so
                // four places are the whole domain and scaling by 10,000 makes
                // every comparison exact. The float version could pass a bill
                // for a hair more than arrived; the same lesson ConsumesFifoStock
                // records, applied to the money side.
                $quantity = $this->scaled($entry['quantity']);
                $alreadyInvoiced = $this->scaled((string) $line->quantity_invoiced);
                $received = $this->scaled((string) $line->quantity_received);
                $returned = $this->scaled((string) $line->quantity_returned);

                // ⚠⚠ The three-way match, D-SB-8. A supplier billing for twelve
                // when eight arrived is the most common overpayment in a small
                // business, and the system knows what arrived.
                //
                // ⚠⚠ RECEIVED MINUS RETURNED, not received. Goods that came in
                // and went back are not billable, and the old check compared
                // against arrivals alone, so a delivery that was returned in
                // full could still be invoiced in full.
                $billable = $received - $returned;

                if ($alreadyInvoiced + $quantity > $billable) {
                    $remaining = $this->unscaled(max(0, $billable - $alreadyInvoiced));

                    throw new InvalidArgumentException(
                        "Cannot invoice {$entry['quantity']} of line #{$line->id}: only {$remaining} of what was received is still uninvoiced."
                    );
                }

                // ⚠ Through Money, rounded once per line, exactly as the
                // payment and credit note actions already do. Summing floats and
                // rounding at the end gives a different answer from rounding
                // each line, and the bill total has to equal what the journal
                // credits to Accounts Payable to the paisa.
                $lineTotal = Money::ofProduct($entry['quantity'], $entry['unitCost']);
                $lineTotals[] = $lineTotal;

                $bill->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $entry['quantity'],
                    'unit_cost' => $entry['unitCost'],
                    'line_total' => $lineTotal->toTaka(),
                ]);

                $line->update([
                    'quantity_invoiced' => $this->unscaled($alreadyInvoiced + $quantity),
                ]);
            }

            $bill->update(['total' => Money::sum($lineTotals)->toTaka()]);

            $this->posting->forBill($bill->fresh(['lines', 'supplier']), $createdBy);

            return $bill->fresh('lines');
        });
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
