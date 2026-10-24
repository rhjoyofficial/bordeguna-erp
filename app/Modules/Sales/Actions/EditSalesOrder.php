<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\DiscountCap;
use App\Modules\Sales\Support\OrderReservations;
use App\Modules\Sales\Support\ParcelWeight;
use App\Modules\Sales\Support\SalesOrderDestination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Editing a sales order (corrections wave 1, 20 Oct 2026, FINAL-PLAN 3.2):
 * an order is a promise, not a posted document, so it is EDITED, with every
 * change logged, while nothing it promised has left the warehouse.
 *
 *   nothing delivered    everything: the order date, customer, brand,
 *                        warehouse, lines, quantities, prices, address and
 *                        delivery charge
 *   partly delivered     the UNDELIVERED part only: a line's quantity never
 *                        below what went out, a delivered line keeps its
 *                        product and price (its challans bill at it, C-SO-4),
 *                        new lines may be added, untouched lines removed; the
 *                        customer, the order date and a billed delivery charge
 *                        stay
 *   delivered, closed,   not edited (Delivered is terminal; reverse a challan
 *   cancelled, rejected  first, or Copy into a new order)
 *
 * ⚠⚠ APPROVAL RUNS AGAIN (FINAL-PLAN 8.1 decision 3) when the agreed total goes
 * UP, or the customer or the items change: the approval goes back to pending,
 * and the editor's own approval is given only as on a new order (holder of
 * sales.orders.approve, and the credit rules agree). When the value only goes
 * DOWN, the approval stands and the change is logged for the Owner's
 * Corrections report as `value_lowered_after_approval`.
 *
 * The price rules are the new order's: a typed price needs sales.price.override
 * unless it is the price the line already carries, and the per-bill discount
 * caps apply. A line given no price keeps its stored price (same product), or
 * is priced by the resolver at the order date.
 */
class EditSalesOrder
{
    public function __construct(
        private readonly ResolveSellingPrice $prices,
        private readonly CheckCustomerCredit $credit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by SalesOrderController::update
     * @return array{order: SalesOrder, reapproval: bool, lowered: bool}
     *
     * @throws ValidationException
     */
    public function handle(SalesOrder $order, array $data, User $by, ?SalesOrderDestination $destination, ?string $deliveryCharge): array
    {
        $result = DB::transaction(function () use ($order, $data, $by, $destination, $deliveryCharge) {
            $order = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->with(['lines.product', 'approvalRequest', 'address'])->firstOrFail();
            $refusal = self::whyNotEditable($order);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['order' => $refusal]);
            }

            $delivered = $order->lines->contains(fn (SalesOrderLine $line) => (float) $line->quantity_delivered > 0.00005);
            $before = $this->snapshot($order);
            $customer = Customer::query()->findOrFail($data['customer_id']);
            $date = Carbon::parse($data['ordered_at'] ?? $order->ordered_at)->startOfDay();
            $warehouse = isset($data['warehouse_id']) ? Warehouse::query()->findOrFail($data['warehouse_id']) : null;

            if ($delivered && (int) $customer->id !== (int) $order->customer_id) {
                throw ValidationException::withMessages(['customer_id' => 'Goods already left for this customer, so the customer stays.']);
            }

            if ($delivered && $date->toDateString() !== $order->ordered_at->toDateString()) {
                throw ValidationException::withMessages(['ordered_at' => 'Goods already left against this order, so its date stays.']);
            }

            if (! $customer->is_active) {
                throw ValidationException::withMessages(['customer_id' => 'That customer is no longer active.']);
            }

            if ($warehouse !== null && ! $warehouse->is_active) {
                throw ValidationException::withMessages(['warehouse_id' => 'That warehouse is no longer active.']);
            }

            $brandId = isset($data['brand_id']) ? (int) $data['brand_id'] : null;

            if ($brandId !== null && $brandId !== (int) $order->brand_id) {
                $brand = Brand::query()->whereKey($brandId)->first();

                if ($brand === null || ! $brand->is_active) {
                    throw ValidationException::withMessages(['brand_id' => 'That brand is not in use.']);
                }
            }

            $chargeChanged = Money::ofTaka((string) ($deliveryCharge ?? '0'))->minor !== $order->deliveryCharge()->minor;

            if ($chargeChanged && $order->deliveryChargeBilled()) {
                throw ValidationException::withMessages(['delivery_charge' => 'The delivery charge is already billed on an invoice, so it stays.']);
            }

            $this->applyLines($order, $data['lines'], $customer, $warehouse, $date, $by, $delivered);

            $order->forceFill([
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse?->id,
                'brand_id' => $brandId ?? $order->brand_id,
                'ordered_at' => $date->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'note' => $data['note'] ?? null,
                'special_instructions' => self::text($data['special_instructions'] ?? null, 500),
                'delivery_charge' => $deliveryCharge,
                // ⭐ B18 (25 Oct 2026): the order discount, when the form sent one.
                ...(array_key_exists('order_discount', $data) ? ['order_discount' => Money::ofTaka((string) ($data['order_discount'] ?? '0'))->toTaka()] : []),
            ])->save();

            $order->address()->delete();

            if ($destination !== null) {
                $order->address()->create(['business_id' => $order->business_id, ...$destination->toRow()]);
            }

            $order->load('lines.product');
            $auto = ParcelWeight::forOrder($order)->autoGrams();
            $weight = isset($data['weight_grams']) ? (int) $data['weight_grams'] : null;

            if ($weight !== null && ($weight < 1 || $weight > ProductWeight::MAX_GRAMS)) {
                throw ValidationException::withMessages(['weight_grams' => 'The parcel weight must be whole grams between 1 and '.ProductWeight::MAX_GRAMS.'.']);
            }

            $typed = $weight !== null && $weight !== $auto;
            $order->forceFill([
                'weight_grams_auto' => $auto,
                'weight_grams' => $typed ? $weight : null,
                'weight_edited_by' => $typed ? $by->id : null,
                'weight_edited_at' => $typed ? now() : null,
            ])->save();

            if ($order->orderDiscount()->minor > Money::ofTaka($order->goodsTotal())->minor) {
                throw ValidationException::withMessages(['lines' => 'The discount on the whole order is now larger than the goods.']);
            }

            $after = $this->snapshot($order->fresh(['lines']));
            $up = $after['total'] > $before['total'];
            $material = $up || $after['customer'] !== $before['customer'] || $after['products'] !== $before['products'];
            $approval = $order->approvalRequest;
            $reapproval = false;
            $lowered = false;

            if ($approval !== null && $approval->status === ApprovalStatus::Returned) {
                // ⭐ B13 (24 Oct 2026): withdrawn by its sender (back to a
                // draft); saving the edit sends it for approval again, the
                // same request row, with the new order's self-approval rule.
                $approval->resubmit();
                $reapproval = true;

                if ($by->can('sales.orders.approve') && $this->credit->refusalFor($order->fresh(['customer'])) === null && OrderReservations::shortfalls($order) === []) {
                    $approval->fresh()->approve($by);
                }
            } elseif ($approval !== null && $approval->status === ApprovalStatus::Approved && $material) {
                // ⚠ The approval goes back to pending, as on a new order.
                $approval->forceFill(['status' => ApprovalStatus::Pending, 'current_stage' => 1, 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null])->save();
                $reapproval = true;

                if ($by->can('sales.orders.approve') && $this->credit->refusalFor($order->fresh(['customer'])) === null && OrderReservations::shortfalls($order) === []) {
                    $approval->fresh()->approve($by);
                }
            } elseif ($approval !== null && $approval->status === ApprovalStatus::Approved && $after['total'] < $before['total']) {
                $lowered = true;
            }

            // ⭐ B17 (25 Oct 2026, Q4 = A): a confirmed order holds the NEW
            // quantities; one waiting for approval again holds nothing (and a
            // short one waits there, as on a new order).
            if ($approval !== null && $approval->fresh()?->status === ApprovalStatus::Approved) {
                app(OrderReservations::class)->resync($order, $by, 'Edited');
            } else {
                app(OrderReservations::class)->release($order, 'Edited: waits for approval again', $by);
            }

            CorrectionLog::event('edited', $by, $order, [
                'before' => $before,
                'after' => $after,
                'approval' => $reapproval ? 'runs again' : ($lowered ? 'stands (value lowered)' : 'unchanged'),
            ]);

            if ($lowered) {
                CorrectionLog::event('value_lowered_after_approval', $by, $order, [
                    'from' => Money::ofMinor($before['total'])->toTaka(),
                    'to' => Money::ofMinor($after['total'])->toTaka(),
                ]);
            }

            return ['order' => $order->fresh(['lines', 'approvalRequest', 'customer']), 'reapproval' => $reapproval, 'lowered' => $lowered];
        });

        if ($result['reapproval'] && $result['order']->approvalRequest?->isPending()) {
            app(NotifyApprovers::class)->handle(
                businessId: $result['order']->business_id,
                permission: 'sales.orders.approve',
                summary: "Sales order {$result['order']->order_number} was changed and needs approval again",
                excludeUserId: $by->id,
            );
        }

        return $result;
    }

    /** Null when the order may be edited; otherwise one sentence. */
    public static function whyNotEditable(SalesOrder $order): ?string
    {
        return match (true) {
            $order->status === SalesOrderStatus::Cancelled => "Order {$order->order_number} is cancelled; copy it into a new order instead.",
            $order->status === SalesOrderStatus::Closed => "Order {$order->order_number} is closed short; copy it into a new order for the rest.",
            $order->status === SalesOrderStatus::Delivered => "Everything on order {$order->order_number} has left; reverse a challan first to change it.",
            $order->approvalRequest?->status === ApprovalStatus::Rejected => "Order {$order->order_number} was rejected; copy it into a new order instead.",
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function applyLines(SalesOrder $order, array $lines, Customer $customer, ?Warehouse $warehouse, Carbon $date, User $by, bool $delivered): void
    {
        $existing = $order->lines->keyBy('id');
        $kept = [];
        $capLines = [];
        $mayOverride = $by->can('sales.price.override');

        $lines = array_values(array_filter($lines, fn (array $l) => (float) ($l['quantity'] ?? 0) > 0 || isset($l['id'])));

        foreach ($lines as $index => $entry) {
            $product = Product::query()->findOrFail($entry['product_id']);
            $quantity = (string) $entry['quantity'];
            $line = isset($entry['id']) ? $existing->get((int) $entry['id']) : null;

            if (isset($entry['id']) && $line === null) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => 'That line is not on this order.']);
            }

            if (! $product->type->isStocked()) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => "{$product->name} is a service and cannot be ordered. Invoice it directly."]);
            }

            $sent = (float) ($line?->quantity_delivered ?? 0);

            if ($line !== null && $sent > 0.00005) {
                if ((int) $product->id !== (int) $line->product_id) {
                    throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Goods on this line already left, so its product stays.']);
                }

                if ((float) $quantity + 0.00005 < $sent) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => "{$sent} of this line already left; it cannot be less."]);
                }

                if (isset($entry['unit_price']) && bccomp((string) $entry['unit_price'], (string) $line->unit_price, 4) !== 0) {
                    throw ValidationException::withMessages(["lines.{$index}.unit_price" => 'Goods on this line already left at its price, so the price stays.']);
                }
            }

            if ((float) $quantity <= 0) {
                if ($sent > 0.00005) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Goods on this line already left; it cannot be removed.']);
                }

                continue;
            }

            $capsBefore = count($capLines);
            // ⭐ B18 (25 Oct 2026): a gift line is given at zero, never discounted.
            $gift = (bool) ($entry['is_gift'] ?? false);

            if ($line !== null && $sent > 0.00005 && $gift !== (bool) $line->is_gift) {
                throw ValidationException::withMessages(["lines.{$index}.is_gift" => 'Goods on this line already left, so whether it is a gift stays.']);
            }

            if ($gift) {
                [$unitPrice, $note] = ['0', 'Gift (free)'];
                $entry['discount'] = '0';
                unset($entry['unit_price']);
            } else {
                [$unitPrice, $note] = $this->priceFor($entry, $line?->is_gift ? null : $line, $product, $customer, $warehouse, $quantity, $date, $mayOverride, $index, $capLines);
            }

            $attributes = [
                'product_id' => $product->id,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
                'price_note' => $note,
                'description' => self::text($entry['description'] ?? null, 255),
                'is_gift' => $gift,
            ];

            // ⭐ 25 Oct 2026 (Joy, Q12 = A, R89): the line discount from the
            // Discount % box. Sent = the figure to keep; absent = unchanged.
            $typedDiscount = isset($entry['discount']) && $entry['discount'] !== '' ? Money::ofTaka((string) $entry['discount']) : null;

            if ($typedDiscount !== null && $typedDiscount->minor !== Money::ofTaka((string) ($line?->line_discount ?? '0'))->minor) {
                if ($sent > 0.00005) {
                    throw ValidationException::withMessages(["lines.{$index}.discount" => 'Goods on this line already left at its discount, so the discount stays.']);
                }

                if ($typedDiscount->minor < 0 || $typedDiscount->minor > Money::ofProduct($quantity, $unitPrice)->minor) {
                    throw ValidationException::withMessages(["lines.{$index}.discount" => "The discount on {$product->name} is larger than the line itself."]);
                }

                $attributes['line_discount'] = $typedDiscount->toTaka();

                if (count($capLines) > $capsBefore) {
                    // The typed price already put this line on the cap list.
                    $capLines[count($capLines) - 1]['discount'] = $typedDiscount->toTaka();
                } else {
                    try {
                        $list = (string) $this->prices->resolve($product, $customer, $quantity, $date, warehouseId: $warehouse?->id)->unitPrice;
                    } catch (NoSellingPriceException) {
                        $list = null;
                    }

                    $capLines[] = ['quantity' => $quantity, 'unitPrice' => $unitPrice, 'discount' => $typedDiscount->toTaka(), 'listPrice' => $list, 'name' => $product->name];
                }
            }

            if ($line === null) {
                $line = $order->lines()->create(['business_id' => $order->business_id, 'line_discount' => '0', ...$attributes]);
            } else {
                $gross = Money::ofProduct($quantity, $unitPrice);

                if (! isset($attributes['line_discount']) && Money::ofTaka((string) $line->line_discount)->minor > $gross->minor) {
                    $attributes['line_discount'] = $gross->toTaka();
                }

                $line->forceFill($attributes)->save();
            }

            $kept[] = (int) $line->id;
        }

        foreach ($existing as $id => $line) {
            if (in_array((int) $id, $kept, true)) {
                continue;
            }

            if ((float) $line->quantity_delivered > 0.00005 || $line->deliveryLines()->exists()) {
                throw ValidationException::withMessages(['lines' => "Goods on {$line->product?->name} already left; that line cannot be removed."]);
            }

            $line->delete();
        }

        if ($kept === []) {
            throw ValidationException::withMessages(['lines' => 'A sales order needs at least one line with a quantity greater than zero.']);
        }

        $overCap = DiscountCap::refusal($by, (int) $order->business_id, $capLines, '0');

        if ($overCap !== null) {
            throw ValidationException::withMessages(['lines' => $overCap]);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $capLines
     * @return array{0: string, 1: string}
     */
    private function priceFor(array $entry, ?SalesOrderLine $line, Product $product, Customer $customer, ?Warehouse $warehouse, string $quantity, Carbon $date, bool $mayOverride, int $index, array &$capLines): array
    {
        $typed = isset($entry['unit_price']) && $entry['unit_price'] !== '' ? (string) $entry['unit_price'] : null;
        $samePriceAsBefore = $line !== null && (int) $line->product_id === (int) $product->id
            && ($typed === null || bccomp($typed, (string) $line->unit_price, 4) === 0);

        if ($samePriceAsBefore) {
            return [(string) $line->unit_price, (string) $line->price_note];
        }

        if ($typed === null) {
            try {
                $resolved = $this->prices->resolve($product, $customer, $quantity, $date, warehouseId: $warehouse?->id);
            } catch (NoSellingPriceException $e) {
                throw ValidationException::withMessages(["lines.{$index}.unit_price" => $e->getMessage()]);
            }

            return [(string) $resolved->unitPrice, $resolved->explain()];
        }

        if (! $mayOverride) {
            throw ValidationException::withMessages(["lines.{$index}.unit_price" => 'You may not change the price. The order takes the price set for this customer.']);
        }

        try {
            $list = (string) $this->prices->resolve($product, $customer, $quantity, $date, warehouseId: $warehouse?->id)->unitPrice;
            $capLines[] = ['quantity' => $quantity, 'unitPrice' => $typed, 'discount' => '0', 'listPrice' => $list, 'name' => $product->name];
        } catch (NoSellingPriceException) {
            // No list price: nothing to measure a discount against.
        }

        return [$typed, 'Manual override'];
    }

    /** @return array{customer: int, products: list<int>, total: int, lines: list<array{0: int, 1: string, 2: string}>} */
    private function snapshot(SalesOrder $order): array
    {
        $products = $order->lines->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return [
            'customer' => (int) $order->customer_id,
            'products' => $products,
            'total' => Money::ofTaka($order->agreedTotal())->minor,
            'lines' => $order->lines->map(fn (SalesOrderLine $l) => [(int) $l->product_id, (string) $l->quantity_ordered, (string) $l->unit_price])->values()->all(),
        ];
    }

    private static function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
