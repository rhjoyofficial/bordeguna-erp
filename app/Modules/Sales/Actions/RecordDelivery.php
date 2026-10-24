<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Foundation\Models\Business;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\SellerStockGate;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\NotForSale;
use App\Support\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods leaving the warehouse for a customer.
 *
 * ⚠⚠ THE SAME ACTION SERVES BOTH FLOWS. A counter sale calls this and then
 * raises the invoice in the same transaction; a distributor challan calls this
 * and is invoiced weeks later. There is no branch here for which one it is, and
 * that is the whole architecture: see SALES-PHASE1-DESIGN.md §2.3.
 *
 * ⚠ Sales never touches `stock_batches` or `stock_movements` directly. It calls
 * Inventory's own RecordStockOut, exactly as Purchasing calls RecordStockIn -
 * the cross-module boundary rule from ERP-PLAN risk #4.
 */
class RecordDelivery
{
    public function __construct(
        private readonly RecordStockOut $recordStockOut,
        private readonly PostSalesJournals $posting,
    ) {}

    /**
     * @param  array<int, array{product: Product, quantity: string, orderLine?: SalesOrderLine|null}>  $lines
     *
     * ⚠ `orderLine` is OPTIONAL, and absent it behaves byte-for-byte as before:
     * a direct delivery, which is every counter sale forever. Present, the line
     * fulfils (part of) a sales order line - capped at what remains ordered,
     * against an APPROVED, still-open order only (C-SO-1/C-SO-5, Joy
     * 2026-08-11). One challan may serve several orders of the same customer;
     * that falls out of per-line linking for free.
     *
     * @throws ValidationException
     */
    public function handle(
        Customer $customer,
        Warehouse $warehouse,
        array $lines,
        User $createdBy,
        mixed $deliveredAt = null,
        ?string $note = null,
    ): Delivery {
        // ⚠⚠ Resolved ONCE, here, and passed to every movement. Resolving it per
        // line would let a delivery straddle midnight and write movements on two
        // different days, after which the document and the ledger disagree about
        // which day it was. EffectiveDate also refuses a future date and asserts
        // the accounting period is open - correct for stock, unlike pricing.
        // ⚠ 19 Oct 2026: a challan CONSUMES stock, so a bare day means the end
        // of that day (today: now). As midnight, a back-dated challan could not
        // take goods received earlier the same day. A full date-time is kept.
        $date = EffectiveDate::resolveConsumingDay($deliveredAt, 'delivered_at');

        if ($warehouse->business_id !== $customer->business_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse belongs to a different business.',
            ]);
        }

        if (! $customer->is_active) {
            throw ValidationException::withMessages([
                'customer_id' => 'That customer is no longer active.',
            ]);
        }

        if (! $warehouse->is_active) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'That warehouse is no longer active.',
            ]);
        }

        // ⭐ Initial plan B21: a retail seller's stock is sold only through
        // his own reported sales (SellerStockGate).
        SellerStockGate::assertMayUse($warehouse);

        $lines = array_values(array_filter($lines, fn (array $entry) => (float) $entry['quantity'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A delivery needs at least one line with a quantity greater than zero.',
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

            // ⚠ A service has no stock and therefore cannot be delivered. It is
            // invoiced directly, with no cost of goods sold behind it. Refused
            // here rather than deeper down, where the message would be about
            // stock rather than about what the user actually did wrong.
            if (! $product->type->isStocked()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.product" => "{$product->name} is a service and cannot be delivered. Invoice it directly.",
                ]);
            }

            // ⭐ B4 (24 Oct 2026; R18): a challan with no order behind it is a
            // sale of its own. A line that delivers an order was judged when
            // the order was written, and is not re-judged here.
            if (($entry['orderLine'] ?? null) === null) {
                NotForSale::refuse($product, "lines.{$index}.product");
            }
        }

        return DB::transaction(function () use ($customer, $warehouse, $lines, $createdBy, $date, $note) {
            $delivery = DocumentNumber::claim('delivery_number', fn () => $this->nextNumber($customer->business_id, $date), fn (string $number) => Delivery::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'delivery_number' => $number,
                'delivered_at' => $date,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]));

            /** @var array<int, SalesOrder> $ordersTouched keyed by order id */
            $ordersTouched = [];

            foreach ($lines as $index => $entry) {
                $orderLine = $this->claimOrderLine($entry, $index, $customer, $ordersTouched);

                $line = $delivery->lines()->create([
                    'business_id' => $delivery->business_id,
                    'product_id' => $entry['product']->id,
                    'quantity' => $entry['quantity'],
                    'sales_order_line_id' => $orderLine?->id,
                ]);

                // ⚠⚠ FIFO decides the cost, and nobody chooses it per sale. This
                // throws InsufficientStockException rather than going negative
                // (C-INV-2), and refuses expired batches - both of which read as
                // bugs at a counter unless the message explains which warehouse
                // it looked in.
                $movements = $this->recordStockOut->handle(
                    product: $entry['product'],
                    warehouse: $warehouse,
                    quantity: $entry['quantity'],
                    performedBy: $createdBy,
                    note: "Delivered on {$delivery->delivery_number}",
                    reason: MovementReason::Sale,
                    occurredAt: $date,
                    // ⭐ Phase 2 (17 Oct 2026): a staff purchase hands over the
                    // batch reserved and priced at approval, not whatever FIFO
                    // finds first today.
                    reservedFor: $entry['reservedFor'] ?? null,
                );

                // ⚠ One line, several movements, one per FIFO layer consumed.
                // The link lives here because `stock_movements.reason_id` is a
                // foreign key to `reasons` and could never hold a line id.
                foreach ($movements as $movement) {
                    $line->movementLinks()->create([
                        'business_id' => $delivery->business_id,
                        'stock_movement_id' => $movement->id,
                    ]);
                }
            }

            // ⚠ The order's status is recomputed INSIDE the delivery
            // transaction, the RecordGoodsReceipt shape - a challan that
            // completed an order and a header still saying "open" is not a
            // state this can reach.
            foreach ($ordersTouched as $order) {
                $this->recomputeStatus($order);
            }

            // ⚠⚠ Dr Goods Delivered Not Invoiced, Cr Stock on Hand - in the same
            // transaction as the document, so stock that has left the building
            // with no ledger entry behind it is not a state this can reach.
            $this->posting->forDelivery($delivery->fresh(['lines.movements', 'customer']), $createdBy);

            return $delivery->fresh(['lines.movements', 'customer', 'warehouse']);
        });
    }

    /**
     * Validate and claim the ordered quantity this line fulfils, or null for a
     * direct delivery.
     *
     * ⚠⚠ I3, its fifth application. The line is RE-READ under lockForUpdate
     * inside the transaction and the remaining check runs against that copy,
     * never the caller's - two clerks (or one double-click) delivering the
     * last cartons would otherwise both pass the check and the order would
     * record more delivered than ordered. The lock serialises real MySQL
     * concurrency; the re-read is what makes it correct (lockForUpdate is a
     * no-op on SQLite).
     *
     * @param  array{product: Product, quantity: string, orderLine?: SalesOrderLine|null}  $entry
     * @param  array<int, SalesOrder>  $ordersTouched
     */
    private function claimOrderLine(array $entry, int $index, Customer $customer, array &$ordersTouched): ?SalesOrderLine
    {
        if (($entry['orderLine'] ?? null) === null) {
            return null;
        }

        $orderLine = SalesOrderLine::whereKey($entry['orderLine']->id)->lockForUpdate()->firstOrFail();
        $order = $ordersTouched[$orderLine->sales_order_id]
            ?? SalesOrder::whereKey($orderLine->sales_order_id)->lockForUpdate()->firstOrFail();

        if ($order->customer_id !== $customer->id) {
            throw ValidationException::withMessages([
                "lines.{$index}.orderLine" => "Order {$order->order_number} belongs to a different customer.",
            ]);
        }

        // ⚠ C-SO-1: goods leave against an APPROVED order only, and never
        // against one that is delivered, closed or cancelled.
        if (! $order->isDeliverable()) {
            throw ValidationException::withMessages([
                "lines.{$index}.orderLine" => "Order {$order->order_number} is not open for delivery.",
            ]);
        }

        if ($orderLine->product_id !== $entry['product']->id) {
            throw ValidationException::withMessages([
                "lines.{$index}.orderLine" => "That order line is for a different product than {$entry['product']->name}.",
            ]);
        }

        // ⚠ C-SO-5 (Joy): over-delivery is REFUSED. The extra goes on a direct
        // line on the same challan - silently inflating quantity_delivered past
        // quantity_ordered would make every order-book report lie by a little.
        // Integer-scaled comparison, the same arithmetic as quantityUninvoiced.
        $remaining = (int) round(((float) $orderLine->quantityRemaining()) * 10000);
        $wanted = (int) round(((float) $entry['quantity']) * 10000);

        if ($wanted > $remaining) {
            throw ValidationException::withMessages([
                "lines.{$index}.orderLine" => "Only {$orderLine->quantityRemaining()} of {$orderLine->product->name} remains on order {$order->order_number}; record the extra as a direct delivery line.",
            ]);
        }

        $orderLine->forceFill([
            'quantity_delivered' => number_format(
                (float) $orderLine->quantity_delivered + (float) $entry['quantity'],
                4, '.', ''
            ),
        ])->save();

        $ordersTouched[$order->id] = $order;

        return $orderLine;
    }

    /**
     * Open / PartiallyDelivered / Delivered, derived from the lines - the
     * RecordGoodsReceipt shape. ⚠ Closed and Cancelled are never produced
     * here: they are explicit actions, and isDeliverable() already refused
     * them before any quantity moved.
     *
     * ⭐ Public (corrections wave 1, 20 Oct 2026): a reversed challan gives its
     * quantities back to the order lines and asks the same question again.
     */
    public static function recomputeStatus(SalesOrder $order): void
    {
        $lines = $order->lines()->get(['quantity_ordered', 'quantity_delivered']);

        $allDelivered = $lines->every(fn (SalesOrderLine $line) => (float) $line->quantity_delivered >= (float) $line->quantity_ordered);
        $anyDelivered = $lines->contains(fn (SalesOrderLine $line) => (float) $line->quantity_delivered > 0);

        $order->update([
            'status' => match (true) {
                $allDelivered => SalesOrderStatus::Delivered,
                $anyDelivered => SalesOrderStatus::PartiallyDelivered,
                default => SalesOrderStatus::Open,
            },
        ]);
    }

    /**
     * ⚠ Read-then-increment, the same as expense vouchers, and the unique index
     * is what turns a collision into an error rather than a duplicate challan
     * number (limitation L5).
     *
     * 14 Oct 2026 (audit #7): the collision no longer reaches the person.
     * DocumentNumber::claim retries the save with the next free number.
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'DC-'.FiscalYear::containing($date, $business)->label().'-';

        // ⚠ Numeric, not text order (14 Oct 2026, SAL-001): see DocumentNumber.
        return DocumentNumber::next(Delivery::query(), 'delivery_number', $prefix);
    }
}
