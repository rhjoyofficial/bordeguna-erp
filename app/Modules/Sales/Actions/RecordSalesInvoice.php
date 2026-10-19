<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
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
use App\Modules\Sales\Support\OrderAdvance;
use App\Modules\Sales\Support\ProductPricing;
use App\Support\DocumentNumber;
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
    public function __construct(
        private readonly PostSalesJournals $posting,
        private readonly ApplyInvoiceTax $tax,
    ) {}

    /**
     * @param  array<int, array{
     *     deliveryLine?: DeliveryLine|null,
     *     product?: Product|null,
     *     description?: string|null,
     *     quantity: string,
     *     unitPrice: string,
     *     discount?: string|null,
     *     listPrice?: string|null,
     *     overrideReason?: string|null,
     *     overriddenBy?: User|null
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
        // ⭐ Whether the prices on THIS invoice already contain the VAT. Null
        // takes the customer's price group, then the business (plan D2). Read
        // only while VAT applies; ignored, and stored false, otherwise.
        ?bool $pricesIncludeTax = null,
        // Why the whole bill was discounted, and who allowed it: the counter's
        // trail (21 Sep 2026). Kept only when given.
        ?string $discountReason = null,
        ?User $discountGivenBy = null,
        // ⭐ Corrections wave 1 (20 Oct 2026): the Owner's one-document pass when
        // a closed month's invoice is corrected (PHASE-0-CONTRACT 1.10), and the
        // invoice this one is an extra charge (debit note) on.
        ?ClosedPeriodPass $pass = null,
        ?SalesInvoice $debitNoteFor = null,
    ): SalesInvoice {
        $date = ($invoiceDate === null ? Carbon::now() : Carbon::parse($invoiceDate))->startOfDay();

        // ⚠ An invoice dated into a closed month would change a figure somebody
        // has already reported. Same lock as everything else that touches the
        // ledger.
        AccountingPeriod::assertOpenFor($date, $pass);

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

        return DB::transaction(function () use ($customer, $lines, $createdBy, $date, $discount, $dueDate, $note, $pricesIncludeTax, $discountReason, $discountGivenBy, $pass, $debitNoteFor) {
            $invoice = DocumentNumber::claim('invoice_number', fn () => $this->nextNumber($customer->business_id, $date), fn (string $number) => SalesInvoice::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'invoice_number' => $number,
                'invoice_date' => $date,
                // ⭐ Never blank (Joy, 26 Sep 2026): a due date typed on the
                // invoice wins; otherwise the customer's credit days from the
                // invoice date, 10 by default, 0 for the walk-in row. Ageing by
                // due date needs every invoice to have one.
                'due_date' => $dueDate === null
                    ? $date->copy()->addDays($customer->creditDays())
                    : Carbon::parse($dueDate)->startOfDay(),
                'note' => $note,
                'created_by' => $createdBy->id,
                'debit_note_for_invoice_id' => $debitNoteFor?->id,
            ]));

            $subtotal = Money::zero();

            // Net billed on this invoice per sales order, in paisa (slice 2.5b).
            $netByOrder = [];
            $deliveryBilled = Money::zero();

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

                // ⚠⚠ THE COUNTER'S PRICE TRAIL (21 Sep 2026). A caller that
                // passes the list price is saying "this line may differ from
                // it". A line that does - a changed price or a discount - keeps
                // what the list said, why and who; one that does not keeps
                // nothing, so NULL always means "sold at its list price".
                // ⚠ Refused here as well as on the screen: a trail that one
                // caller can skip is not a trail.
                $listPrice = $entry['listPrice'] ?? null;

                // ⭐⭐ D-B (Joy, 18 Oct 2026): a line sold to the proprietor's own
                // customer account at the owner price keeps the reference price
                // (MRP, else today's or the walk-in price) as its list price and
                // the trail "Owner price: batch cost", so the discount he gets
                // is visible on every invoice, whichever screen raised it.
                // ⚠ Only a line the caller did not already concede: a price a
                // cashier changed keeps the cashier's own trail.
                $ownerSale = $customer->isOwner();
                $ownerProduct = $entry['product'] ?? ($entry['deliveryLine'] ?? null)?->product;

                if ($ownerSale && $ownerProduct !== null && ($listPrice === null || bccomp((string) $unitPrice, (string) $listPrice, 4) === 0) && $lineDiscount->minor === 0) {
                    $reference = ProductPricing::capReference($ownerProduct, $date);

                    if ($reference !== null && bccomp($reference['amount'], (string) $unitPrice, 4) > 0) {
                        $listPrice = $reference['amount'];
                        $entry['overrideReason'] = 'Owner price: batch cost (D-B, proprietor buys at cost)';
                        $entry['overriddenBy'] = $entry['overriddenBy'] ?? $createdBy;
                    }
                }

                $overridden = $listPrice !== null && bccomp((string) $unitPrice, (string) $listPrice, 4) !== 0;
                $conceded = $listPrice !== null && ($overridden || $lineDiscount->minor > 0);

                if ($conceded && (trim((string) ($entry['overrideReason'] ?? '')) === '' || ($entry['overriddenBy'] ?? null) === null)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.override_reason" => 'A changed price or a discount needs a reason and the person who allowed it.',
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

                    // ⚠ Bug hunt 16 Oct 2026: an invoice dated before the challan
                    // it bills released cost from GDNI before the delivery put
                    // it there, and booked COGS in a month the goods never left.
                    $deliveredOn = $deliveryLine->delivery->delivered_at?->toDateString();

                    if ($deliveredOn !== null && $deliveredOn > $date->toDateString()) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.deliveryLine" => "Challan {$deliveryLine->delivery->delivery_number} went out on {$deliveredOn}. Its invoice cannot be dated before that.",
                        ]);
                    }

                    // ⚠⚠ A challan out with a courier is invoiced when the courier
                    // confirms delivery (Joy, 13 September), never before: billing
                    // it by hand would bill a parcel the customer may refuse.
                    if ($open = $deliveryLine->delivery->openConsignment()) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.deliveryLine" => "Challan {$deliveryLine->delivery->delivery_number} is out with {$open->courier->name} (consignment {$open->consignment_number}). It is invoiced when the courier's answer is recorded.",
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
                    // ⭐ Owner, 17 Oct 2026: a line's note on the order ("gift
                    // wrap") travels to the invoice unless the caller wrote one.
                    'description' => ($entry['description'] ?? null) ?: ($deliveryLine?->sales_order_line_id === null
                        ? null
                        : SalesOrderLine::query()->whereKey($deliveryLine->sales_order_line_id)->value('description')),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'list_price' => $conceded ? $listPrice : null,
                    'price_overridden' => $overridden,
                    'override_reason' => $conceded ? trim((string) $entry['overrideReason']) : null,
                    'overridden_by' => $conceded ? $entry['overriddenBy']->id : null,
                    // ⭐ Phase 2 (17 Oct 2026): a staff purchase line. Its
                    // list_price is the cap reference price (MRP or market
                    // price), its unit_price the batch-cost staff price.
                    'is_staff_sale' => (bool) ($entry['isStaffSale'] ?? false),
                    // ⭐ D-B (18 Oct 2026): sold to the proprietor's own account.
                    'is_owner_sale' => $ownerSale,
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

                if ($order === null) {
                    continue;
                }

                // ⭐⭐ THE DELIVERY CHARGE THE ORDER AGREED rides on the FIRST
                // invoice that bills any of its goods, whole, as a line of its
                // own crediting 4300 Delivery Charges Income (Joy, 13 September).
                // Whole rather than shared, because a parcel is carried once
                // however many invoices its goods are billed on.
                // ⚠ Decided under the lock above; the unique index on
                // `billed_delivery_for_order_id` refuses a second one regardless.
                $deliveryCharge = $order->deliveryCharge();

                if ($deliveryCharge->minor > 0 && ! $order->deliveryChargeBilled()) {
                    $invoice->lines()->create([
                        'business_id' => $invoice->business_id,
                        'billed_delivery_for_order_id' => $order->id,
                        // ⭐ Corrections wave 1: the UNIQUE "billed once" key,
                        // cleared only when this invoice is credited in full.
                        'live_delivery_for_order_id' => $order->id,
                        'description' => "Delivery charge ({$order->order_number})",
                        'quantity' => '1',
                        'unit_price' => $deliveryCharge->toTaka(),
                        'line_discount' => '0',
                        'line_net' => $deliveryCharge->toTaka(),
                        'cost_released' => '0',
                    ]);

                    $subtotal = $subtotal->plus($deliveryCharge);
                    $deliveryBilled = $deliveryBilled->plus($deliveryCharge);
                }

                if ($order->orderDiscount()->isZero()) {
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
            // ⚠ Measured against the GOODS: a discount is never taken off a
            // delivery charge, which is billed exactly as the order agreed.
            if ($discount->minor > $subtotal->minor - $deliveryBilled->minor) {
                throw ValidationException::withMessages([
                    'invoice_discount' => 'The discount cannot exceed the invoice subtotal for goods. A delivery charge is not discounted.',
                ]);
            }

            // ⭐⭐ VAT (plan D1-D3). Zero, and nothing written, while the business
            // is not charging VAT on this date - which is every business today.
            // ⚠ The tax point is the invoice date in v1: this ERP raises the
            // invoice with the dispatch. It is STORED so the rule can sharpen
            // later without anybody re-reading an old document.
            $inclusive = $this->pricesIncludeTax($customer, $pricesIncludeTax);
            $taxTotal = $this->tax->handle($invoice, $discount, $inclusive, $date);
            $taxed = ! $taxTotal->isZero() || $invoice->lines()->where('tax_rate', '>', 0)->exists();

            // Added on top of an exclusive price; already inside an inclusive one.
            $total = Money::ofMinor($subtotal->minor - $discount->minor + ($inclusive ? 0 : $taxTotal->minor));

            $invoice->forceFill([
                'subtotal' => $subtotal->toTaka(),
                // ⭐ Phase 2: the header carries the flag so the retail KPIs
                // can leave staff sales out in one WHERE (plan E2).
                'is_staff_sale' => $invoice->lines()->where('is_staff_sale', true)->exists(),
                // ⭐ D-B (18 Oct 2026): an owner sale leaves the retail KPIs the
                // same way, and stays in every financial report.
                'is_owner_sale' => $customer->isOwner(),
                'invoice_discount' => $discount->toTaka(),
                'discount_reason' => $discountReason !== null && trim($discountReason) !== '' ? trim($discountReason) : null,
                'discount_given_by' => $discountReason !== null && trim($discountReason) !== '' ? $discountGivenBy?->id : null,
                'tax_total' => $taxTotal->toTaka(),
                'tax_point_date' => $date,
                'prices_include_tax' => $taxed && $inclusive,
                'total' => $total->toTaka(),
            ])->save();

            $this->posting->forInvoice($invoice->fresh(['lines', 'customer']), $createdBy, $pass);

            // ⭐⭐ MONEY THE CUSTOMER ALREADY PAID FOR THESE ORDERS settles this
            // invoice (SSLCommerz slice P1, Joy 13 September): an allocation, no
            // journal, because the receipt and this invoice both already sit in
            // 1140. Under the order lock, so two invoices for one order cannot
            // both take the same money.
            foreach (array_keys($netByOrder) as $orderId) {
                $order = SalesOrder::query()->whereKey($orderId)->lockForUpdate()->first();

                if ($order !== null) {
                    OrderAdvance::applyTo($invoice, $order);
                }
            }

            return $invoice->fresh(['lines', 'customer']);
        });
    }

    /**
     * Do this invoice's prices already contain the VAT?
     *
     * The clerk's choice wins; otherwise the customer's price group says (a
     * retail price is what the shopper pays, a distributor's list may be net),
     * and a customer with no group follows the business.
     */
    private function pricesIncludeTax(Customer $customer, ?bool $chosen): bool
    {
        if ($chosen !== null) {
            return $chosen;
        }

        $group = $customer->priceGroup;

        if ($group !== null) {
            return (bool) $group->prices_include_tax;
        }

        return (bool) Business::withoutGlobalScopes()->whereKey($customer->business_id)->value('prices_include_tax');
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
            // ⭐ Corrections wave 1: a credited invoice's cost went back to GDNI,
            // so only live invoices count as having released any.
            ((float) SalesInvoiceLine::where('delivery_line_id', $deliveryLine->id)->whereHas('invoice', fn ($q) => $q->whereNull('reversed_at'))->sum('cost_released')) * 100
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

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(SalesInvoice::query(), 'invoice_number', $prefix);
    }

    private function scale(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }
}
