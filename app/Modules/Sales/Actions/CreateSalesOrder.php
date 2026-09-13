<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
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
     * @param  array<int, array{product: Product, quantity: string, unit_price?: string|null, discount?: string|null, bundle_label?: string|null}>  $lines
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
        }

        $order = DB::transaction(function () use ($customer, $warehouse, $lines, $createdBy, $date, $expectedDate, $note, $origin) {
            $order = SalesOrder::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse?->id,
                // ⚠ The dimensions D17 asked for, and they are simply absent on
                // an order somebody typed in - which is what null means in both
                // columns. See SalesChannel and SalesOrderOrigin.
                'store_id' => $origin?->storeId,
                'channel' => $origin?->channel,
                'order_number' => $this->nextNumber($customer->business_id, $date),
                'shop_reference' => $origin?->shopReference,
                'ordered_at' => $date->toDateString(),
                'expected_date' => $expectedDate,
                // ⚠⚠ FROZEN HERE, exactly like the unit price below and for the
                // same reason: a basket asks the rate table what delivery costs
                // every time it is read, but an order records what the customer
                // agreed to pay. A rate corrected next week must not rewrite it.
                'delivery_charge' => $origin?->deliveryCharge,
                'payment_method' => $origin?->paymentMethod,
                'status' => SalesOrderStatus::Open,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

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
                    $priceNote = 'Manual override';
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
                ]);
            }

            $order->submitForApproval($createdBy);

            if ($createdBy->can('sales.orders.approve')) {
                $order->approvalRequest->approve($createdBy);
            }

            return $order->fresh(['lines', 'approvalRequest', 'customer']);
        });

        // Only notify when it actually needs review - a self-approved order
        // does not. Outside the transaction, same as CreatePurchaseOrder.
        if ($order->approvalRequest->isPending()) {
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
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'SO-'.FiscalYear::containing($date, $business)->label().'-';

        $latest = SalesOrder::query()
            ->where('order_number', 'like', $prefix.'%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
