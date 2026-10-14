<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Actions\RestoreStockIssue;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Sales\Enums\SalesReturnDisposition;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnInvoiceLine;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\Models\SalesReturnLineMovement;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods that left on a challan come back (SALES-RETURNS-ARCHITECTURE.md, every
 * Correction answered 2026-08-12).
 *
 * ⚠⚠ ANCHORED TO DELIVERY LINES, MONEY DERIVED. The physical truth lives on the
 * challan - which product, which FIFO layers at which cost - and how much of it
 * was invoiced decides the financial treatment per line, uninvoiced first
 * (C-SR-4). Nobody at the counter chooses "GDNI reversal or AR credit".
 *
 * ⚠⚠ ONE LINE MAY SPLIT ON INSPECTION (C-SR-3): part back to stock - restored
 * onto the very batches the sale consumed, newest-consumed first, at frozen
 * layer cost - and part written off, which never re-enters the stock asset.
 * The customer's credit is identical either way; disposition only decides
 * where the cost goes.
 *
 * ⚠ A return NEVER reopens a sales order (C-SR-8): the promise was fulfilled,
 * the return is a new event, and `quantity_delivered` stays untouched.
 */
class RecordSalesReturn
{
    public function __construct(
        private readonly RestoreStockIssue $restoreStockIssue,
        private readonly PostSalesJournals $posting,
    ) {}

    /**
     * @param  array<int, array{deliveryLine: DeliveryLine, quantityToStock: string, quantityWrittenOff: string}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        Delivery $delivery,
        array $lines,
        User $createdBy,
        mixed $returnedAt = null,
        ?string $note = null,
    ): SalesReturn {
        // Resolved once for the document and every movement - the RecordDelivery
        // rule. Refuses future dates and closed periods.
        $date = EffectiveDate::resolve($returnedAt, 'returned_at');

        $lines = array_values(array_filter(
            $lines,
            fn (array $entry) => (float) ($entry['quantityToStock'] ?? '0') > 0
                || (float) ($entry['quantityWrittenOff'] ?? '0') > 0,
        ));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A return needs at least one line with a quantity greater than zero.',
            ]);
        }

        // ⚠ Goods on a parcel still with a courier come back through the
        // courier's answer, which is also what decides the invoice.
        if ($open = $delivery->openConsignment()) {
            throw ValidationException::withMessages([
                'lines' => "Challan {$delivery->delivery_number} is out with {$open->courier->name} (consignment {$open->consignment_number}). Record the courier's answer to bring it back.",
            ]);
        }

        return DB::transaction(function () use ($delivery, $lines, $createdBy, $date, $note) {
            $return = DocumentNumber::claim('return_number', fn () => $this->nextNumber($delivery->business_id, $date), fn (string $number) => SalesReturn::create([
                'business_id' => $delivery->business_id,
                'customer_id' => $delivery->customer_id,
                'delivery_id' => $delivery->id,
                'return_number' => $number,
                'returned_at' => $date,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]));

            $creditTotal = Money::zero();
            $taxTotal = Money::zero();
            /** @var array<int, Money> $creditByInvoice keyed by invoice id */
            $creditByInvoice = [];

            foreach ($lines as $index => $entry) {
                // ⚠⚠ I3 again: the line is RE-READ under lockForUpdate and every
                // cap below runs against that copy. Two clerks returning the
                // last cartons of one challan would otherwise both pass and the
                // line would record more returned than delivered.
                $line = DeliveryLine::query()
                    ->whereKey($entry['deliveryLine']->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($line->delivery_id !== $delivery->id) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.deliveryLine" => 'That line belongs to a different delivery.',
                    ]);
                }

                $toStock = $this->scale($entry['quantityToStock'] ?? '0');
                $writeOff = $this->scale($entry['quantityWrittenOff'] ?? '0');
                $quantity = $toStock + $writeOff;

                if ($toStock < 0 || $writeOff < 0 || $quantity <= 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => 'Return quantities cannot be negative, and at least one must be greater than zero.',
                    ]);
                }

                $returnable = $this->scale($line->quantityReturnable());

                if ($quantity > $returnable) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => sprintf(
                            'Only %s of that delivery line can still be returned.',
                            $line->quantityReturnable(),
                        ),
                    ]);
                }

                // C-SR-4: the uninvoiced portion is relieved first; the rest
                // credits what was invoiced, at the invoiced price.
                $uninvoiced = min($quantity, max(0, $this->scale($line->quantityUninvoiced())));
                $invoiced = $quantity - $uninvoiced;

                $returnLine = $return->lines()->create([
                    'business_id' => $return->business_id,
                    'delivery_line_id' => $line->id,
                    'quantity' => $this->unscale($quantity),
                    'quantity_to_stock' => $this->unscale($toStock),
                    'quantity_written_off' => $this->unscale($writeOff),
                ]);

                [$costRestored, $costWrittenOff] = $this->walkLayers($returnLine, $line, $toStock, $writeOff, $createdBy, $date, $return->return_number);

                $gdniRelief = $this->gdniRelief($line, $uninvoiced);

                // ⚠⚠ COGS relief is the BALANCING figure, not a valuation of its
                // own. GDNI is relieved pro-rata (the same arithmetic invoicing
                // releases cost with), the stock and write-off sides are layer-
                // exact, and whatever the difference is lands COGS on the
                // layer-exact truth for the units that STAY sold. It may be
                // negative when the returned layers were cheaper than the line
                // average - that is a debit, not an error.
                $cogsRelief = Money::ofMinor(
                    $costRestored->minor + $costWrittenOff->minor - $gdniRelief->minor,
                );

                [$creditGross, $creditDiscount, $creditNet, $creditTax, $taxInsideGross, $taxOnDiscount] = $this->creditInvoicedPortion($returnLine, $line, $invoiced, $creditByInvoice);

                $returnLine->forceFill([
                    'gdni_relief' => $gdniRelief->toTaka(),
                    'cogs_relief' => $cogsRelief->toTaka(),
                    'cost_restored' => $costRestored->toTaka(),
                    'cost_written_off' => $costWrittenOff->toTaka(),
                    'credit_gross' => $creditGross->toTaka(),
                    'credit_discount' => $creditDiscount->toTaka(),
                    'credit_net' => $creditNet->toTaka(),
                    'tax_amount' => $creditTax->toTaka(),
                    'tax_inside_gross' => $taxInsideGross->toTaka(),
                    'tax_on_discount' => $taxOnDiscount->toTaka(),
                ])->save();

                $creditTotal = $creditTotal->plus($creditNet);
                $taxTotal = $taxTotal->plus($creditTax);

                $line->forceFill([
                    'quantity_returned' => number_format(
                        (float) $line->quantity_returned + (float) $this->unscale($quantity), 4, '.', ''
                    ),
                    'quantity_returned_uninvoiced' => number_format(
                        (float) $line->quantity_returned_uninvoiced + (float) $this->unscale($uninvoiced), 4, '.', ''
                    ),
                ])->save();
            }

            $return->forceFill(['credit_total' => $creditTotal->toTaka(), 'tax_total' => $taxTotal->toTaka()])->save();

            $this->allocateCredit($return, $creditByInvoice);

            // In the same transaction as the movements, like every other
            // Phase 3 integration: goods back on the shelf with no ledger entry
            // behind them is not a state this can reach.
            $this->posting->forSalesReturn($return->fresh(['lines', 'customer']), $createdBy);

            return $return->fresh(['lines.movementLinks', 'lines.invoiceLineCredits', 'allocations', 'customer']);
        });
    }

    /**
     * Walk this line's OUT movements NEWEST-consumed-first (§3: reverse order,
     * so what remains consumed is still a FIFO prefix), assigning the
     * back-to-stock share first and the write-off share after it.
     *
     * ⚠⚠ Capacity comes from sales_return_line_movements - BOTH dispositions -
     * never from movements alone: a write-off consumed a layer's returnable
     * share without writing any movement, and missing that would restore the
     * same layer twice with every journal balancing.
     *
     * @return array{0: Money, 1: Money} [cost restored, cost written off]
     */
    private function walkLayers(
        SalesReturnLine $returnLine,
        DeliveryLine $line,
        int $toStock,
        int $writeOff,
        User $createdBy,
        Carbon $date,
        string $returnNumber,
    ): array {
        $issues = $line->movements()->orderByDesc('stock_movements.id')->get();

        $claimed = SalesReturnLineMovement::query()
            ->whereIn('stock_movement_id', $issues->pluck('id'))
            ->selectRaw('stock_movement_id, SUM(quantity) as total')
            ->groupBy('stock_movement_id')
            ->pluck('total', 'stock_movement_id');

        $plan = [];

        foreach ($issues as $issue) {
            $capacity = $this->scale((string) $issue->quantity)
                - $this->scale((string) ($claimed[$issue->id] ?? '0'));

            if ($capacity <= 0) {
                continue;
            }

            if ($toStock > 0) {
                $take = min($toStock, $capacity);
                $plan[] = ['movement' => $issue, 'quantity' => $take, 'disposition' => SalesReturnDisposition::Stock];
                $toStock -= $take;
                $capacity -= $take;
            }

            if ($writeOff > 0 && $capacity > 0) {
                $take = min($writeOff, $capacity);
                $plan[] = ['movement' => $issue, 'quantity' => $take, 'disposition' => SalesReturnDisposition::WriteOff];
                $writeOff -= $take;
            }

            if ($toStock <= 0 && $writeOff <= 0) {
                break;
            }
        }

        // The line-level cap already proved enough capacity exists in total;
        // a shortfall here means the capacity ledger and the delivery line
        // disagree, which is a defect worth failing loudly on.
        if ($toStock > 0 || $writeOff > 0) {
            throw ValidationException::withMessages([
                'lines' => "Delivery line #{$line->id} has less layer capacity than quantity_returned admits - the return tables disagree with the delivery line.",
            ]);
        }

        $restorations = array_values(array_filter($plan, fn (array $p) => $p['disposition'] === SalesReturnDisposition::Stock));

        $restored = collect();

        if ($restorations !== []) {
            // ⚠ Inventory writes the IN movements - Sales never touches
            // stock_movements directly, the cross-module boundary rule.
            $restored = $this->restoreStockIssue->handle(
                restorations: array_map(fn (array $p) => [
                    'movement' => $p['movement'],
                    'quantity' => $this->unscale($p['quantity']),
                ], $restorations),
                performedBy: $createdBy,
                note: "Returned on {$returnNumber}",
                occurredAt: $date,
            );
        }

        $costRestored = Money::zero();
        $costWrittenOff = Money::zero();
        $restoredIndex = 0;

        foreach ($plan as $entry) {
            /** @var StockMovement $issue */
            $issue = $entry['movement'];

            if ($entry['disposition'] === SalesReturnDisposition::Stock) {
                /** @var StockMovement $in */
                $in = $restored[$restoredIndex++];
                $value = Money::ofProduct((string) $in->quantity, (string) $in->unit_cost);
                $costRestored = $costRestored->plus($value);

                $returnLine->movementLinks()->create([
                    'business_id' => $returnLine->business_id,
                    'stock_movement_id' => $issue->id,
                    'restored_movement_id' => $in->id,
                    'quantity' => $this->unscale($entry['quantity']),
                    'disposition' => SalesReturnDisposition::Stock,
                    'unit_cost' => $in->unit_cost,
                ]);

                continue;
            }

            // ⚠ Write-offs are valued at the OUT movement's frozen cost - the
            // very figure the delivery credited Stock with - and write NO
            // movement: destroyed goods must never re-enter the stock asset.
            $value = Money::ofProduct($this->unscale($entry['quantity']), (string) $issue->unit_cost);
            $costWrittenOff = $costWrittenOff->plus($value);

            $returnLine->movementLinks()->create([
                'business_id' => $returnLine->business_id,
                'stock_movement_id' => $issue->id,
                'restored_movement_id' => null,
                'quantity' => $this->unscale($entry['quantity']),
                'disposition' => SalesReturnDisposition::WriteOff,
                'unit_cost' => $issue->unit_cost,
            ]);
        }

        return [$costRestored, $costWrittenOff];
    }

    /**
     * How much the uninvoiced portion relieves GDNI: pro-rata-with-remainder
     * over the line's delivered cost - the exact arithmetic family
     * RecordSalesInvoice::costToRelease uses, so between the two of them a
     * fully settled line empties GDNI to exactly zero, never stranding paisa.
     */
    private function gdniRelief(DeliveryLine $line, int $uninvoiced): Money
    {
        if ($uninvoiced <= 0) {
            return Money::zero();
        }

        $totalCost = $line->costValue();

        $released = (int) round(
            ((float) SalesInvoiceLine::where('delivery_line_id', $line->id)->sum('cost_released')) * 100
        );
        $relieved = (int) round(
            ((float) SalesReturnLine::where('delivery_line_id', $line->id)->sum('gdni_relief')) * 100
        );

        $lineQuantity = $this->scale((string) $line->quantity);
        $settledAfter = $this->scale((string) $line->quantity_invoiced)
            + $this->scale((string) $line->quantity_returned_uninvoiced)
            + $uninvoiced;

        if ($lineQuantity <= 0) {
            return Money::zero();
        }

        if ($settledAfter >= $lineQuantity) {
            return Money::ofMinor($totalCost->minor - $released - $relieved);
        }

        return Money::ofMinor((int) round($totalCost->minor * $uninvoiced / $lineQuantity));
    }

    /**
     * Credit the invoiced portion at the price the customer ACTUALLY paid:
     * walk this delivery line's invoice lines newest first, gross at the
     * invoiced price (line discount already in line_net), claw back the
     * pro-rated share of any invoice-level discount, remainder discipline
     * per invoice line so a fully returned line credits its net exactly.
     *
     * ⭐ VAT (plan D6): the return gives back the VAT the ORIGINAL line was
     * charged, pro rata, last portion taking what is left. `net` is what the
     * customer is credited, so where VAT was added on top it includes the VAT;
     * where the price contained it, `gross` already does.
     *
     * @param  array<int, Money>  $creditByInvoice
     * @return array{0: Money, 1: Money, 2: Money, 3: Money, 4: Money, 5: Money} [gross, discount, net, tax, taxInsideGross, taxOnDiscount]
     */
    private function creditInvoicedPortion(
        SalesReturnLine $returnLine,
        DeliveryLine $line,
        int $invoiced,
        array &$creditByInvoice,
    ): array {
        $gross = Money::zero();
        $discount = Money::zero();
        $net = Money::zero();
        $tax = Money::zero();
        $taxInsideGross = Money::zero();
        $taxOnDiscount = Money::zero();

        if ($invoiced <= 0) {
            return [$gross, $discount, $net, $tax, $taxInsideGross, $taxOnDiscount];
        }

        $invoiceLines = SalesInvoiceLine::query()
            ->where('delivery_line_id', $line->id)
            ->with('invoice')
            ->orderByDesc('id')
            ->get();

        $remaining = $invoiced;

        foreach ($invoiceLines as $invoiceLine) {
            if ($remaining <= 0) {
                break;
            }

            $already = SalesReturnInvoiceLine::query()
                ->where('sales_invoice_line_id', $invoiceLine->id)
                ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(gross), 0) as gross, COALESCE(SUM(discount), 0) as discount, COALESCE(SUM(tax), 0) as tax, COALESCE(SUM(tax_on_discount), 0) as tax_on_discount')
                ->first();

            $lineQuantity = $this->scale((string) $invoiceLine->quantity);
            $alreadyQty = $this->scale((string) $already->qty);
            $available = $lineQuantity - $alreadyQty;

            if ($available <= 0) {
                continue;
            }

            $take = min($remaining, $available);

            $lineNet = Money::ofTaka((string) $invoiceLine->line_net);

            // The line's share of the invoice-level discount, pro-rated by its
            // net over the subtotal - the same proportion the posting debited
            // Discounts Allowed with, sliced per line.
            $invoice = $invoiceLine->invoice;
            // ⚠ Over the GOODS subtotal: a delivery charge line carries no share
            // of the discount, so it must not dilute the goods lines' shares.
            $subtotal = $invoice->goodsSubtotal();
            $invoiceDiscount = Money::ofTaka((string) $invoice->invoice_discount);
            $lineDiscountShare = $subtotal->minor > 0
                ? (int) round($invoiceDiscount->minor * $lineNet->minor / $subtotal->minor)
                : 0;

            // The VAT frozen onto the invoice line, and the VAT inside its
            // discount share. Both zero on anything invoiced without VAT.
            $lineTax = Money::ofTaka((string) $invoiceLine->tax_amount);
            $lineTaxOnDiscount = Money::ofTaka((string) $invoiceLine->tax_on_discount);

            if ($take >= $available) {
                // Remainder discipline: the last portion takes what is LEFT of
                // the line's net and discount share, so nothing strands.
                $takeGross = Money::ofMinor($lineNet->minor - Money::ofTaka((string) $already->gross)->minor);
                $takeDiscount = Money::ofMinor($lineDiscountShare - Money::ofTaka((string) $already->discount)->minor);
                $takeTax = Money::ofMinor($lineTax->minor - Money::ofTaka((string) $already->tax)->minor);
                $takeTaxOnDiscount = Money::ofMinor($lineTaxOnDiscount->minor - Money::ofTaka((string) $already->tax_on_discount)->minor);
            } else {
                $takeGross = Money::ofMinor((int) round($lineNet->minor * $take / $lineQuantity));
                $takeDiscount = Money::ofMinor((int) round($lineDiscountShare * $take / $lineQuantity));
                $takeTax = Money::ofMinor((int) round($lineTax->minor * $take / $lineQuantity));
                $takeTaxOnDiscount = Money::ofMinor((int) round($lineTaxOnDiscount->minor * $take / $lineQuantity));
            }

            // ⚠ Added on top of an exclusive price, so the customer is owed it
            // back on top; an inclusive price already holds it inside `gross`.
            $inclusive = (bool) $invoiceLine->prices_include_tax;
            $takeNet = Money::ofMinor($takeGross->minor - $takeDiscount->minor + ($inclusive ? 0 : $takeTax->minor));

            $returnLine->invoiceLineCredits()->create([
                'business_id' => $returnLine->business_id,
                'sales_invoice_line_id' => $invoiceLine->id,
                'quantity' => $this->unscale($take),
                'gross' => $takeGross->toTaka(),
                'discount' => $takeDiscount->toTaka(),
                'net' => $takeNet->toTaka(),
                'tax' => $takeTax->toTaka(),
                'tax_on_discount' => $takeTaxOnDiscount->toTaka(),
            ]);

            $creditByInvoice[$invoice->id] = ($creditByInvoice[$invoice->id] ?? Money::zero())->plus($takeNet);

            $gross = $gross->plus($takeGross);
            $discount = $discount->plus($takeDiscount);
            $net = $net->plus($takeNet);
            $tax = $tax->plus($takeTax);
            $taxOnDiscount = $taxOnDiscount->plus($takeTaxOnDiscount);

            if ($inclusive) {
                $taxInsideGross = $taxInsideGross->plus($takeTax)->plus($takeTaxOnDiscount);
            }

            $remaining -= $take;
        }

        // The arithmetic above cannot leave a remainder while the delivery
        // line's counters are consistent; failing loudly beats crediting less
        // than the goods that came back.
        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'lines' => "Delivery line #{$line->id} has {$this->unscale($remaining)} invoiced units unaccounted for across its invoice lines.",
            ]);
        }

        return [$gross, $discount, $net, $tax, $taxInsideGross, $taxOnDiscount];
    }

    /**
     * Apply the net credit against the invoices it came from, newest first,
     * capped at each invoice's outstanding (re-read under lock, so a racing
     * receipt cannot make the same taka settle twice). Residue stays
     * unallocated - credit-on-account, which reconciliation warns about
     * exactly as it does for unallocated receipts.
     *
     * @param  array<int, Money>  $creditByInvoice
     */
    private function allocateCredit(SalesReturn $return, array $creditByInvoice): void
    {
        if ($creditByInvoice === []) {
            return;
        }

        krsort($creditByInvoice);

        // ⚠ Never allocate credit that was already refunded or allocated: the
        // same refundable() figure caps both paths, so one taka of credit is
        // either cash back or a smaller invoice, never both.
        $unspent = $return->refundable()->minor;

        foreach ($creditByInvoice as $invoiceId => $credit) {
            if ($credit->minor <= 0 || $unspent <= 0) {
                continue;
            }

            $invoice = SalesInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $outstanding = $invoice->outstanding();

            $amount = Money::ofMinor(min($credit->minor, $unspent, max(0, $outstanding->minor)));

            if ($amount->minor <= 0) {
                continue;
            }

            $return->allocations()->create([
                'business_id' => $return->business_id,
                'sales_invoice_id' => $invoice->id,
                'amount' => $amount->toTaka(),
            ]);

            $unspent -= $amount->minor;
        }
    }

    /**
     * ⚠ Read-then-increment, the Sales numbering convention; the unique index
     * turns a collision into an error rather than a duplicate number.
     *
     * 14 Oct 2026 (audit #7): the collision no longer reaches the person.
     * DocumentNumber::claim retries the save with the next free number.
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'SRN-'.FiscalYear::containing($date, $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(SalesReturn::query(), 'return_number', $prefix);
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscale(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
