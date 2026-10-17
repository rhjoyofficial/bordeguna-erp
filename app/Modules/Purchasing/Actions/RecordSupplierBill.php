<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TaxCalculator;
use App\Modules\Accounting\Support\TaxSplit;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
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
    public function __construct(
        private readonly PostPurchaseJournals $posting,
        private readonly TaxCalculator $tax,
    ) {}

    /**
     * Each line carries its quantity and EITHER `unitCost` OR `lineTotal`
     * (17 Oct 2026, Phase 1 part A); `entryMode` says which was typed when
     * both are sent. A line with only `unitCost` is a rate entry, as before.
     *
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string, unitCost?: ?string, lineTotal?: ?string, entryMode?: ?string}>  $lines
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
        // ⭐ VAT (plan D7). Does the unit cost typed from the supplier's bill
        // already contain the VAT? Read only while this business charges VAT.
        // Each line may also carry `taxRate` (the percentage the SUPPLIER
        // printed, which is the truth for a purchase) and `taxRecoverable`.
        bool $pricesIncludeTax = false,
    ): SupplierBill {
        $date = Carbon::parse($billDate);

        // The generalised period lock. A bill dated into a closed month would
        // change a figure somebody has already reported.
        AccountingPeriod::assertOpenFor($date);

        // ⚠⚠ The oldest control in accounts payable, checked before anything is
        // written: the same invoice number from the same supplier twice is how a
        // business pays for one delivery twice. The database enforces it too;
        // this exists to fail with a sentence rather than a constraint violation.
        //
        // ⚠ PUR-004 (owner decision 14 Oct 2026): only a LIVE bill holds its
        // number. A reversed bill's number is free for the corrected bill, so
        // the stored number matches the supplier's paper; the database index
        // on `active_number_key` says the same (see the 14 Oct migration).
        $duplicate = SupplierBill::where('supplier_id', $supplier->id)
            ->where('bill_number', $billNumber)
            ->whereNull('reversed_at')
            ->first();

        if ($duplicate !== null) {
            throw new InvalidArgumentException(
                "Bill {$billNumber} has already been recorded for {$supplier->name}. If that bill is wrong, reverse it first; its number is then free for the corrected bill."
            );
        }

        $business = Business::withoutGlobalScopes()->find($supplier->business_id ?? app(BusinessContext::class)->id());
        $taxed = $this->tax->appliesOn($business, $date);

        return DB::transaction(function () use ($supplier, $billNumber, $date, $lines, $createdBy, $purchaseOrder, $dueDate, $note, $business, $taxed, $pricesIncludeTax) {
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
            $taxTotal = Money::zero();

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
                // ⭐ 17 Oct 2026: a bill line typed as a TOTAL keeps that total
                // to the paisa and shows a six-place rate; typed as a rate it is
                // quantity x rate as it always was (PurchaseLineEntry).
                $typed = PurchaseLineEntry::resolve(
                    (string) $entry['quantity'],
                    isset($entry['unitCost']) ? (string) $entry['unitCost'] : null,
                    isset($entry['lineTotal']) ? (string) $entry['lineTotal'] : null,
                    $entry['entryMode'] ?? null,
                );
                $lineTotal = $typed['line_total'];

                // ⭐⭐ VAT ON A PURCHASE (plan D7). `line_total` stays what was
                // typed. The split decides how much of it is the goods and how
                // much is VAT; `tax_recoverable` decides where that VAT goes:
                // claimable VAT to Input VAT Receivable with stock carried NET,
                // anything else left inside the cost of what was bought.
                // ⚠ Nothing here runs while the business charges no VAT, and the
                // bill is then exactly the bill it always was.
                $split = TaxSplit::untaxed($lineTotal);
                $classId = null;
                $recoverable = false;

                if ($taxed) {
                    $classId = $line->product?->tax_class_id;
                    $rate = isset($entry['taxRate']) && $entry['taxRate'] !== ''
                        ? (string) $entry['taxRate']
                        : $this->tax->rateFor($business, $classId, $date);

                    $split = $this->tax->split($lineTotal, $rate, $pricesIncludeTax);
                    $recoverable = ! $split->tax->isZero() && (bool) ($entry['taxRecoverable'] ?? true);
                }

                // What the supplier is owed for this line, VAT and all.
                $lineTotals[] = $split->gross;
                $taxTotal = $taxTotal->plus($split->tax);

                $bill->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity' => $entry['quantity'],
                    'unit_cost' => $typed['unit_cost'],
                    'line_total' => $lineTotal->toTaka(),
                    'entry_mode' => $typed['entry_mode'],
                    'tax_class_id' => $classId,
                    'tax_rate' => $split->rate,
                    'tax_amount' => $split->tax->toTaka(),
                    'prices_include_tax' => $taxed && $pricesIncludeTax,
                    'tax_recoverable' => $recoverable,
                ]);

                $line->update([
                    'quantity_invoiced' => $this->unscaled($alreadyInvoiced + $quantity),
                ]);
            }

            // ⚠ `total` is what the supplier is OWED: the goods plus any VAT,
            // whichever way round the costs were typed.
            $bill->forceFill([
                'total' => Money::sum($lineTotals)->toTaka(),
                'tax_total' => $taxTotal->toTaka(),
                'tax_point_date' => $date,
                'prices_include_tax' => $taxed && $pricesIncludeTax,
            ])->save();

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
