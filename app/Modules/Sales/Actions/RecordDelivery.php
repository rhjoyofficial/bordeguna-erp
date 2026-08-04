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
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
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
     * @param  array<int, array{product: Product, quantity: string}>  $lines
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
        $date = EffectiveDate::resolve($deliveredAt, 'delivered_at');

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
        }

        return DB::transaction(function () use ($customer, $warehouse, $lines, $createdBy, $date, $note) {
            $delivery = Delivery::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'delivery_number' => $this->nextNumber($customer->business_id, $date),
                'delivered_at' => $date,
                'note' => $note,
                'created_by' => $createdBy->id,
            ]);

            foreach ($lines as $entry) {
                $line = $delivery->lines()->create([
                    'business_id' => $delivery->business_id,
                    'product_id' => $entry['product']->id,
                    'quantity' => $entry['quantity'],
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

            // ⚠⚠ Dr Goods Delivered Not Invoiced, Cr Stock on Hand - in the same
            // transaction as the document, so stock that has left the building
            // with no ledger entry behind it is not a state this can reach.
            $this->posting->forDelivery($delivery->fresh(['lines.movements', 'customer']), $createdBy);

            return $delivery->fresh(['lines.movements', 'customer', 'warehouse']);
        });
    }

    /**
     * ⚠ Read-then-increment, the same as expense vouchers, and the unique index
     * is what turns a collision into an error rather than a duplicate challan
     * number (limitation L5).
     */
    private function nextNumber(int $businessId, Carbon $date): string
    {
        $business = Business::withoutGlobalScopes()->findOrFail($businessId);
        $prefix = 'DC-'.FiscalYear::containing($date, $business)->label().'-';

        $latest = Delivery::query()
            ->where('delivery_number', 'like', $prefix.'%')
            ->orderByDesc('delivery_number')
            ->value('delivery_number');

        $next = $latest === null ? 1 : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
