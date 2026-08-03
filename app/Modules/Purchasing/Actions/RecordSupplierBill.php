<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
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

            $total = 0.0;

            foreach ($lines as $entry) {
                // ⚠ Re-read under a lock, the same lesson as I3: two people
                // billing the same delivery from stale copies would each pass
                // the check below and the second would overwrite the first.
                $line = PurchaseOrderLine::whereKey($entry['line']->id)->lockForUpdate()->firstOrFail();

                $quantity = (float) $entry['quantity'];
                $alreadyInvoiced = (float) $line->quantity_invoiced;
                $received = (float) $line->quantity_received;

                // ⚠⚠ The three-way match. A supplier billing for twelve when
                // eight arrived is the most common overpayment in a small
                // business, and the system knows what arrived.
                if ($alreadyInvoiced + $quantity > $received) {
                    $remaining = $received - $alreadyInvoiced;

                    throw new InvalidArgumentException(
                        "Cannot invoice {$entry['quantity']} of line #{$line->id}: only {$remaining} of what was received is still uninvoiced."
                    );
                }

                $lineTotal = $quantity * (float) $entry['unitCost'];
                $total += $lineTotal;

                $bill->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $entry['quantity'],
                    'unit_cost' => $entry['unitCost'],
                    'line_total' => number_format($lineTotal, 4, '.', ''),
                ]);

                $line->update(['quantity_invoiced' => number_format($alreadyInvoiced + $quantity, 4, '.', '')]);
            }

            $bill->update(['total' => number_format($total, 4, '.', '')]);

            $this->posting->forBill($bill->fresh(['lines', 'supplier']), $createdBy);

            return $bill->fresh('lines');
        });
    }
}
