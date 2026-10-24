<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\NotForSale;
use App\Modules\Sales\Support\ParcelWeight;
use App\Modules\Sales\Support\SalesOrderOrigin;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking an order: the moment the price is agreed and the commitment made.
 *
 * ⚠⚠ NO STOCK MOVES AND NO JOURNAL POSTS HERE, ever. The order is a recorded
 * promise; stock leaves at delivery and revenue is earned at invoicing,
 * untouched from Phase 1. See SALES-ORDER-ARCHITECTURE.md §2, §7.
 *
 * ⚠⚠ THE PRICE IS COPIED ONTO THE LINE AT ORDER TIME, with the resolver's
 * explanation beside it - or a manual override, marked as such. C-SO-4 (Joy):
 * when a February challan is invoiced in March after the price rule changed,
 * the ORDER price is billed. That only works if the agreement is written down
 * here, at the moment it was made.
 *
 * ⚠ Approval mirrors CreatePurchaseOrder exactly (C-SO-1): submit on creation,
 * self-approve for holders of `sales.orders.approve`, notify approvers
 * otherwise. Goods only leave against an approved order (slice 2).
 */
class CreateSalesOrder
{
    public function __construct(
        private readonly ResolveSellingPrice $prices,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: string, unit_price?: string|null, discount?: string|null, bundle_label?: string|null, price_note?: string|null, description?: string|null}>  $lines
     * @param  string|null  $specialInstructions  for the packer and the rider (17 Oct 2026)
     * @param  bool  $selfApprove  false = always left waiting for approval, even
     *                             for a holder of `sales.orders.approve` (the
     *                             shop order import approves afterwards, through
     *                             ApproveSalesOrder, only when asked to)
     * @param  bool  $notifyApprovers  false = the caller sends one notice for a
     *                                 whole batch instead of one per order
     *
     * @throws ValidationException
     * @throws NoSellingPriceException
     */
    public function handle(
        Customer $customer,
        array $lines,
        User $createdBy,
        ?Warehouse $warehouse = null,
        mixed $orderedAt = null,
        ?string $expectedDate = null,
        ?string $note = null,
        ?SalesOrderOrigin $origin = null,
        ?int $weightGrams = null,
        ?int $brandId = null,
        bool $selfApprove = true,
        bool $notifyApprovers = true,
        ?string $specialInstructions = null,
    ): SalesOrder {
        // ⚠ Deliberately NOT EffectiveDate::resolve - that helper asserts the
        // accounting period is open, which guards events that post. An order
        // posts nothing, so there is no period to protect. Same reasoning as
        // the price resolver's own date handling.
        $date = $orderedAt instanceof Carbon ? $orderedAt : ($orderedAt === null ? Carbon::now() : Carbon::parse($orderedAt));

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => 'That customer is no longer active.',
            ]);
        }

        if ($warehouse !== null && $warehouse->business_id !== $customer->business_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse belongs to a different business.',
            ]);
        }

        if ($warehouse !== null && ! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse is no longer active.',
            ]);
        }

        // ⭐ Joy, 11 Oct 2026: the order carries its brand. Typed on the form,
        // else the shop's own. ⚠ A brand of this business and still in use,
        // never one a browser named from somewhere else.
        $brandId ??= $origin?->brandId;

        if ($brandId !== null) {
            $brand = Brand::query()->withoutGlobalScopes()->where('business_id', $customer->business_id)->whereKey($brandId)->first();

            if ($brand === null) {
                throw ValidationException::withMessages(['brand_id' => 'That brand belongs to a different business.']);
            }

            if (! $brand->is_active) {
                throw ValidationException::withMessages(['brand_id' => "{$brand->name} is no longer in use."]);
            }
        }

        $lines = array_values(array_filter($lines, fn (array $entry) => (float) $entry['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A sales order needs at least one line with a quantity greater than zero.',
            ]);
        }

        foreach ($lines as $index => $entry) {
            /** @var Product $product */
            $product = $entry['product'];

            if ($product->business_id !== $customer->business_id) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product" => 'That product belongs to a different business.',
                ]);
            }

            // ⚠ A service cannot be delivered, so its quantity_delivered would
            // never move and the order could never complete. Services keep
            // their existing path: invoiced directly, no delivery, no order.
            // The delivery-side rule, mirrored (limitation L4 of the doc).
            if (! $product->type->isStocked()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product" => "{$product->name} is a service and cannot be ordered. Invoice it directly.",
                ]);
            }

            // ⭐ B4 (24 Oct 2026; R18): every order path lands here (the form,
            // the website, the shop import, HQ, an accepted quotation).
            NotForSale::refuse($product, "lines.{$index}.product");
        }

        if ($weightGrams !== null && ($weightGrams < 1 || $weightGrams > ProductWeight::MAX_GRAMS)) {
            throw ValidationException::withMessages([
                'weight_grams' => 'The parcel weight must be whole grams between 1 and '.ProductWeight::MAX_GRAMS.'.',
            ]);
        }

        $order = DB::transaction(function () use ($customer, $warehouse, $lines, $createdBy, $date, $expectedDate, $note, $origin, $weightGrams, $brandId, $selfApprove, $specialInstructions) {
            $order = DocumentNumber::claim('order_number', fn () => $this->nextNumber($customer->business_id, $date), fn (string $number) => SalesOrder::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse?->id,
                // ⚠ The dimensions D17 asked for, and they are simply absent on
                // an order somebody typed in - which is what null means in both
                // columns. See SalesChannel and SalesOrderOrigin.
                'store_id' => $origin?->storeId,
                'channel' => $origin?->channel,
                'brand_id' => $brandId,
                'order_number' => $number,
                'shop_reference' => $origin?->shopReference,
                'ordered_at' => $date->toDateString(),
                'expected_date' => $expectedDate,
                // ⚠⚠ FROZEN HERE, exactly like the unit price below and for the
                // same reason: a basket asks the rate table what delivery costs
                // every time it is read, but an order records what the customer
                // agreed to pay. A rate corrected next week must not rewrite it.
                'delivery_charge' => $origin?->deliveryCharge,
                // Slice 2.5b: a coupon's discount on the whole order, and the
                // code as a word. Checked against the goods below, once the
                // engine has priced them.
                'order_discount' => Money::ofTaka($origin?->orderDiscount ?? '0')->toTaka(),
                'coupon_code' => $origin?->couponCode,
                // ⚠ The promise the shop made, frozen; the date is written at
                // confirmation by ApproveSalesOrder, where the promise starts.
                'delivery_promise_min_hours' => $origin?->promiseMinHours,
                'delivery_promise_max_hours' => $origin?->promiseMaxHours,
                'payment_method' => $origin?->paymentMethod,
                'status' => SalesOrderStatus::Open,
                'note' => $note,
                'special_instructions' => self::text($specialInstructions, 500),
                'created_by' => $createdBy->id,
            ]));

            // ⚠ Written inside the same transaction as the order. An order with
            // no destination is a parcel nobody can book, and the two rows are
            // one fact - there is no moment at which having one without the
            // other is correct.
            if ($origin?->destination !== null) {
                $order->address()->create([
                    'business_id' => $order->business_id,
                    ...$origin->destination->toRow(),
                ]);
            }

            foreach ($lines as $index => $entry) {
                // ⚠⚠ ONE pricing engine. An override is taken verbatim and
                // marked as such; anything else is the resolver's answer AT THE
                // ORDER DATE, for THIS customer, in THIS quantity - and the
                // explanation travels with the number, the counter's habit
                // carried onto the document where a dispute will be settled.
                $override = $entry['unit_price'] ?? null;

                if ($override !== null) {
                    $unitPrice = $override;
                    // ⚠ Still marked as an override; an importer says where it came from.
                    $priceNote = ($entry['price_note'] ?? null) ?: 'Manual override';
                } else {
                    // ⭐⭐ PRICED AS THE CHANNEL WHEN THE CHANNEL IS A PRICE
                    // GROUP, which is the whole content of ADR-005. A website
                    // basket is priced with no customer at the shop's group; if
                    // this line then priced at the BUYER's group, a dealer
                    // would be quoted retail on the page and billed dealer on
                    // the document. The customer is still recorded as the buyer
                    // - only their pricing identity is set aside, and only for
                    // a channel that has decided to be B2C.
                    //
                    // ⚠ Still ONE engine, asked a different question. There is
                    // no second query and no branch inside the resolver.
                    $resolved = $this->prices->resolve(
                        product: $entry['product'],
                        customer: ($origin?->pricesAsChannel ?? false) ? null : $customer,
                        quantity: $entry['quantity'],
                        on: $date,
                        priceGroupId: $origin?->priceGroupId,
                        // ⭐ D-B (18 Oct 2026): an owner order is priced at the
                        // FIFO batch cost of the order's warehouse.
                        warehouseId: $warehouse?->id,
                    );
                    $unitPrice = $resolved->unitPrice;
                    $priceNote = $resolved->explain();
                }

                // ⚠ An agreed discount is checked against the price the ENGINE
                // just resolved, not against a figure the caller computed. The
                // same two refusals the invoice makes, for the same reasons: a
                // negative discount is a surcharge wearing the wrong name, and
                // one larger than the line is a refund, not a discount.
                $discount = Money::ofTaka((string) ($entry['discount'] ?? '0'));
                $gross = Money::ofProduct((string) $entry['quantity'], (string) $unitPrice);

                if ($discount->minor < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.discount" => 'A discount cannot be negative.',
                    ]);
                }

                if ($discount->minor > $gross->minor) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.discount" => "The discount on {$entry['product']->name} is larger than the line itself.",
                    ]);
                }

                $order->lines()->create([
                    'business_id' => $order->business_id,
                    'product_id' => $entry['product']->id,
                    'quantity_ordered' => $entry['quantity'],
                    'unit_price' => $unitPrice,
                    'line_discount' => $discount->toTaka(),
                    'price_note' => $priceNote,
                    'bundle_label' => $entry['bundle_label'] ?? null,
                    'description' => self::text($entry['description'] ?? null, 255),
                ]);
            }

            // ⚠ An order discount is checked against the goods the ENGINE priced,
            // net of line discounts. Larger than the goods it is not a discount
            // but money owed to the customer, which is a different document.
            $order->load('lines.product');

            // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): the parcel weight from
            // the lines, frozen like the prices. A typed weight is taken as it
            // is - no reason asked - and the worked-out one is kept beside it
            // with who typed it, silently, for the later fee analysis.
            $auto = ParcelWeight::forOrder($order)->autoGrams();
            $typed = $weightGrams !== null && $weightGrams !== $auto;

            $order->update([
                'weight_grams_auto' => $auto,
                'weight_grams' => $typed ? $weightGrams : null,
                'weight_edited_by' => $typed ? $createdBy->id : null,
                'weight_edited_at' => $typed ? now() : null,
            ]);

            if ($order->orderDiscount()->minor < 0 || $order->orderDiscount()->minor > Money::ofTaka($order->goodsTotal())->minor) {
                throw ValidationException::withMessages([
                    'order_discount' => 'The discount on the whole order cannot be negative or larger than the goods.',
                ]);
            }

            // ⭐ An order waiting for its online payment is not sent for approval
            // yet (SSLCommerz slice P2c): BookGatewayPayment sends it once the
            // money is booked, and an abandoned payment page never reaches the
            // queue at all.
            if ($origin?->awaitingPayment !== true) {
                $order->submitForApproval($createdBy);

                // ⚠⚠ Approving one's own order is still an approval, so the
                // credit rules apply here too (21 Sep 2026): an order they refuse
                // is saved but left pending, for an Owner to decide with a reason.
                // Without this a manager's own order would skip the check.
                if ($selfApprove && $createdBy->can('sales.orders.approve') && app(CheckCustomerCredit::class)->refusalFor($order) === null) {
                    $order->approvalRequest->approve($createdBy);
                }
            }

            return $order->fresh(['lines', 'approvalRequest', 'customer']);
        });

        // Only notify when it actually needs review - a self-approved order
        // does not. Outside the transaction, same as CreatePurchaseOrder.
        if ($notifyApprovers && $order->approvalRequest?->isPending()) {
            app(NotifyApprovers::class)->handle(
                businessId: $order->business_id,
                permission: 'sales.orders.approve',
                summary: "Sales order {$order->order_number} for {$customer->name}",
                excludeUserId: $createdBy->id,
            );
        }

        return $order;
    }

    /**
     * ⚠ Read-then-increment, the same as challans and invoices, and the unique
     * index is what turns a collision into an error rather than a duplicate
     * order number (Phase 1 limitation L5 applies here too).
     *
     * 14 Oct 2026 (audit #7): the collision no longer reaches the person.
     * DocumentNumber::claim retries the save with the next free number.
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'SO-'.FiscalYear::containing($date, $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(SalesOrder::query(), 'order_number', $prefix);
    }

    /** Trimmed, blank as null, cut to the column. */
    private static function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
