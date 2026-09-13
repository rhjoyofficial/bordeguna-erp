<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesInvoiceOrderDiscount;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Models\SalesReturnLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing a customer for what they took.
 *
 * ⚠⚠ TWO THINGS HAPPEN AT ONCE, and they must. The customer owes money, and the
 * cost of the goods they took moves out of Goods Delivered Not Invoiced into
 * Cost of Goods Sold. Both land in one journal on the invoice date, which is
 * what puts a July challan's cost in the August it was earned in.
 *
 * ⚠ A line either bills a delivery line or it does not. One that does releases
 * cost; a service line does not, because there is no stock behind it.
 */
class RecordSalesInvoice
{
    public function __construct(private readonly PostSalesJournals $posting) {}

    /**
     * @param  array<int, array{
     *     deliveryLine?: DeliveryLine|null,
     *     product?: Product|null,
     *     description?: string|null,
     *     quantity: string,
     *     unitPrice: string,
     *     discount?: string|null
     * }>  $lines
     *
     * @throws ValidationException
     */
    public function handle(
        Customer $customer,
        array $lines,
        User $createdBy,
        mixed $invoiceDate = null,
        string $invoiceDiscount = '0',
        mixed $dueDate = null,
        ?string $note = null,
    ): SalesInvoice {
        $date = ($invoiceDate === null ? Carbon::now() : Carbon::parse($invoiceDate))->startOfDay();

        // ⚠ An invoice dated into a closed month would change a figure somebody
        // has already reported. Same lock as everything else that touches the
        // ledger.
        AccountingPeriod::assertOpenFor($date);

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => 'That customer is no longer active.',
            ]);
        }

        $lines = array_values(array_filter($lines, fn (array $entry) => (float) $entry['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'An invoice needs at least one line with a quantity greater than zero.',
            ]);
        }

        $discount = Money::ofTaka($invoiceDiscount);

        if ($discount->minor < 0) {
            throw ValidationException::withMessages([
                'invoice_discount' => 'A discount cannot be negative.',
            ]);
        }

        return DB::transaction(function () use ($customer, $lines, $createdBy, $date, $discount, $dueDate, $note) {
            $invoice = SalesInvoice::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'invoice_number' => $this->nextNumber($customer->business_id, $date),
                'invoice_date' => $date,
                'due_date' => $dueDate === null ? null : Carbon::parse($dueDate)->startOfDay(),
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            $subtotal = Money::zero();

            // Net billed on this invoice per sales order, in paisa (slice 2.5b).
            $netByOrder = [];

            foreach ($lines as $index => $entry) {
                $quantity = $entry['quantity'];
                $unitPrice = $entry['unitPrice'];

                $gross = Money::ofProduct($quantity, $unitPrice);
                $lineDiscount = Money::ofTaka($entry['discount'] ?? '0');

                if ($lineDiscount->minor < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.discount" => 'A discount cannot be negative.',
                    ]);
                }

                // ⚠ A line discount larger than the line is not a discount, it
                // is a refund, and refusing it here is cheaper than explaining a
                // negative revenue line later.
                if ($lineDiscount->minor > $gross->minor) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.discount" => 'A line discount cannot exceed the line total.',
                    ]);
                }

                $net = Money::ofMinor($gross->minor - $lineDiscount->minor);
                $subtotal = $subtotal->plus($net);

                $deliveryLine = $entry['deliveryLine'] ?? null;
                $costReleased = Money::zero();
                $productId = $entry['product']?->id ?? null;

                if ($deliveryLine !== null) {
                    // ⚠⚠ RE-READ UNDER A LOCK inside the transaction, never the
                    // caller's copy. Two clerks invoicing the same challan at
                    // once is the realistic way a delivery gets billed twice,
                    // and the re-read is what makes this correct - lockForUpdate
                    // is a no-op on SQLite, so a lock-only fix is green in the
                    // suite and races in production. Fifth application.
                    $deliveryLine = DeliveryLine::query()
                        ->whereKey($deliveryLine->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $deliveryLine->loadMissing('delivery', 'movements');

                    if ($deliveryLine->delivery->customer_id !== $customer->id) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.deliveryLine" => 'That delivery belongs to a different customer.',
                        ]);
                    }

                    $uninvoiced = $this->scale($deliveryLine->quantityUninvoiced());
                    $billing = $this->scale($quantity);

                    // ⚠ Billing more than was delivered is not an invoice, it is
                    // a second sale of goods that never left the warehouse - and
                    // it would release cost that is not in GDNI.
                    if ($billing > $uninvoiced) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.quantity" => sprintf(
                                'Only %s of that delivery line is still uninvoiced.',
                                $deliveryLine->quantityUninvoiced(),
                            ),
                        ]);
                    }

                    $costReleased = $this->costToRelease($deliveryLine, $billing);
                    $productId ??= $deliveryLine->product_id;

                    $deliveryLine->forceFill([
                        'quantity_invoiced' => number_format(
                            ((float) $deliveryLine->quantity_invoiced) + (float) $quantity, 4, '.', ''
                        ),
                    ])->save();

                    if ($deliveryLine->sales_order_line_id !== null) {
                        $orderId = (int) SalesOrderLine::query()->whereKey($deliveryLine->sales_order_line_id)->value('sales_order_id');
                        $netByOrder[$orderId] = ($netByOrder[$orderId] ?? 0) + $net->minor;
                    }
                }

                $invoice->lines()->create([
                    'business_id' => $invoice->business_id,
                    'delivery_line_id' => $deliveryLine?->id,
                    'product_id' => $productId,
                    'description' => $entry['description'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_discount' => $lineDiscount->toTaka(),
                    'line_net' => $net->toTaka(),
                    'cost_released' => $costReleased->toTaka(),
                ]);
            }

            // ⭐⭐ THE DISCOUNT AN ORDER AGREED ON THE WHOLE ORDER - a coupon (slice
            // 2.5b) - is billed here, on the invoice, where it posts to 4210
            // Discounts Allowed exactly as a typed whole-invoice discount does
            // (Joy, 13 September). It is ADDED to anything typed rather than
            // replaced by it: the agreement with the customer is not the clerk's
            // to withdraw, and an extra concession is.
            //
            // ⚠ Decided here, in the action, under a lock on the order, so two
            // clerks invoicing parts of one order at once cannot both bill the
            // same share. The invoice that finishes the order bills whatever is
            // still unbilled; every other bills a share of what it covers.
            foreach ($netByOrder as $orderId => $netMinor) {
                /** @var SalesOrder|null $order */
                $order = SalesOrder::query()->whereKey($orderId)->lockForUpdate()->with('lines.deliveryLines')->first();

                if ($order === null || $order->orderDiscount()->isZero()) {
                    continue;
                }

                $share = $order->orderDiscountToBill(Money::ofMinor($netMinor), $order->isFullyInvoiced());

                if ($share->isZero()) {
                    continue;
                }

                SalesInvoiceOrderDiscount::create([
                    'business_id' => $invoice->business_id,
                    'sales_invoice_id' => $invoice->id,
                    'sales_order_id' => $order->id,
                    'amount' => $share->toTaka(),
                ]);

                $discount = $discount->plus($share);
            }

            // ⚠ Discounting more than the bill would make the customer owe a
            // negative amount, which is a credit note - a different document.
            if ($discount->minor > $subtotal->minor) {
                throw ValidationException::withMessages([
                    'invoice_discount' => 'The discount cannot exceed the invoice subtotal.',
                ]);
            }

            $total = Money::ofMinor($subtotal->minor - $discount->minor);

            $invoice->forceFill([
                'subtotal' => $subtotal->toTaka(),
                'invoice_discount' => $discount->toTaka(),
                // ⚠ Zero until C-SLS-3 is answered. The column is the seam.
                'tax_total' => '0',
                'total' => $total->toTaka(),
            ])->save();

            $this->posting->forInvoice($invoice->fresh(['lines', 'customer']), $createdBy);

            return $invoice->fresh(['lines', 'customer']);
        });
    }

    /**
     * How much cost this billing releases from Goods Delivered Not Invoiced.
     *
     * ⚠⚠ WHEN THE LAST PORTION IS INVOICED, RELEASE THE REMAINDER rather than
     * another rounded fraction. Pro-rata parts do not necessarily sum back to
     * the whole, and a few stranded paisa in GDNI would be indistinguishable
     * from a real unbilled balance - a bridge account that never quite clears
     * stops being a report anybody can act on.
     */
    private function costToRelease(DeliveryLine $deliveryLine, int $billingScaled): Money
    {
        $totalCost = $deliveryLine->costValue();

        $alreadyReleased = (int) round(
            ((float) SalesInvoiceLine::where('delivery_line_id', $deliveryLine->id)->sum('cost_released')) * 100
        );

        // ⚠⚠ Returns relieve GDNI too (C-SR-4): units returned before billing
        // took their pro-rata share of this line's cost out of the bridge, so
        // the remainder arm below must release what is LEFT after both exits -
        // or the last invoice would strand the returned units' cost in GDNI
        // forever, and a bridge that never quite clears stops being a report.
        $gdniRelieved = (int) round(
            ((float) SalesReturnLine::where('delivery_line_id', $deliveryLine->id)->sum('gdni_relief')) * 100
        );

        $lineQuantity = $this->scale((string) $deliveryLine->quantity);
        $settledAfter = $this->scale((string) $deliveryLine->quantity_invoiced)
            + $this->scale((string) $deliveryLine->quantity_returned_uninvoiced)
            + $billingScaled;

        if ($lineQuantity <= 0) {
            return Money::zero();
        }

        // ⚠ Fully settled means every unit either billed or returned unbilled -
        // a line with returns is never "fully invoiced", and the old condition
        // would have kept pro-rating forever.
        if ($settledAfter >= $lineQuantity) {
            return Money::ofMinor($totalCost->minor - $alreadyReleased - $gdniRelieved);
        }

        return Money::ofMinor((int) round($totalCost->minor * $billingScaled / $lineQuantity));
    }

    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'INV-'.FiscalYear::containing($date, $business)->label().'-';

        $latest = SalesInvoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }
}
