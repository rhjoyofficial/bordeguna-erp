<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderImport;
use App\Modules\Sales\Models\SalesOrderImportOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\SalesOrderOrigin;
use App\Modules\Sales\Support\ShopOrderImportPlan;
use App\Modules\Sales\Support\StagedShopOrderFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes the orders a shop order sheet's plan describes (Joy, 11 Oct 2026).
 *
 * ⚠⚠ EVERY ORDER GOES THROUGH CreateSalesOrder. The price engine, the frozen
 * delivery charge, the address, the auto weight and the approval request are
 * the same as an order typed on the screen; nothing here writes an order row
 * of its own.
 *
 * ⚠ One transaction PER ORDER, so one order the database refuses (a shop
 * number imported a moment ago by somebody else) costs that order only, and
 * the import records why.
 *
 * ⚠ Approval: every order is created WAITING. With "auto-approve" (only for a
 * holder of `sales.orders.approve`) each is then approved through
 * ApproveSalesOrder, so the credit rules apply exactly as on the screen; one
 * they refuse stays waiting. Approvers get one notice for the whole file.
 *
 * ⭐ Past parcels (Joy, 26 Oct 2026): an order with a consignment id is
 * created, approved, confirmed, sent on its challan, booked with the courier
 * and recorded Delivered, ALL in its one transaction (sendPastParcel). Any
 * step that refuses leaves nothing of that order, and the import says why.
 * They go first, oldest parcel first, so FIFO gives each the batch it really
 * took and a back-dated challan never needs a batch that came in later.
 */
class ApplyShopOrderImport
{
    public function __construct(
        private readonly CreateSalesOrder $create,
        private readonly RecordOrderPayment $payments,
        private readonly ApproveSalesOrder $approve,
        private readonly RecordDelivery $deliveries,
        private readonly BookConsignment $consignments,
        private readonly RecordConsignmentOutcome $outcomes,
        private readonly ResolveSellingPrice $prices,
    ) {}

    /**
     * @param  array<string, MoneyAccount>  $moneyAccounts  advance method => the account it was paid into
     *
     * @throws ValidationException
     */
    public function handle(
        ShopOrderImportPlan $plan,
        StagedShopOrderFile $file,
        string $fileName,
        int $businessId,
        User $by,
        bool $autoApprove,
        array $moneyAccounts,
    ): SalesOrderImport {
        if ($plan->hasErrors()) {
            throw ValidationException::withMessages(['file' => 'That file has '.count($plan->errors).' error(s), so no orders were imported.']);
        }

        $autoApprove = $autoApprove && $by->can('sales.orders.approve');

        $first = SalesOrderImport::query()->where('business_id', $businessId)->where('file_sha256', $file->hash)->first();

        if ($first !== null) {
            throw ValidationException::withMessages(['file' => "This exact file was already imported on {$first->created_at?->format('Y-m-d H:i')} (import #{$first->id})."]);
        }

        $path = $file->keep($businessId);

        try {
            $import = SalesOrderImport::create([
                'business_id' => $businessId,
                'file_name' => mb_substr($fileName, 0, 255),
                'file_path' => $path,
                'file_sha256' => $file->hash,
                'file_size' => $file->size,
                'rows' => $plan->rows,
                'auto_approve' => $autoApprove,
                'money_mappings' => array_map(fn (MoneyAccount $a) => $a->id, $moneyAccounts),
                'created_by' => $by->id,
            ]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['file' => 'This exact file was imported a moment ago. Open the order list to see it.']);
        }

        $created = $skipped = $failed = 0;
        $waiting = 0;

        foreach ($plan->skipped as $skip) {
            $this->record($import, $skip['order_ref'], SalesOrderImportOrder::SKIPPED, "Already imported as {$skip['order_number']}.", $skip['sales_order_id']);
            $skipped++;
        }

        foreach (self::inApplyOrder($plan->orders) as $order) {
            $parcel = $order['parcel'] ?? null;

            try {
                $salesOrder = DB::transaction(function () use ($order, $parcel, $import, $businessId, $by, $moneyAccounts) {
                    $salesOrder = $this->createOne($order, $import, $businessId, $by, $moneyAccounts);

                    if ($parcel !== null) {
                        $this->sendPastParcel($salesOrder, $order, $parcel, $import, $by, $moneyAccounts);
                    }

                    return $salesOrder;
                });
            } catch (QueryException $e) {
                // The unique shop number: somebody imported it in the meantime.
                $existing = SalesOrder::query()->withoutGlobalScopes()->where('shop_reference', $order['order_ref'])->first();

                if ($existing !== null && (int) $existing->business_id === $businessId) {
                    $this->record($import, $order['order_ref'], SalesOrderImportOrder::SKIPPED, "Already imported as {$existing->order_number}.", (int) $existing->id, $order);
                    $skipped++;
                } else {
                    report($e);
                    $this->record($import, $order['order_ref'], SalesOrderImportOrder::FAILED, 'The database refused this order.', null, $order);
                    $failed++;
                }

                continue;
            } catch (Throwable $e) {
                $message = match (true) {
                    $e instanceof ValidationException => (string) collect($e->errors())->flatten()->first(),
                    // The batch-date refusal, in the challan form's words.
                    $e instanceof InvalidArgumentException => SalesOrderController::challanDateProblem($e->getMessage()),
                    default => $e->getMessage(),
                };

                if ($parcel !== null) {
                    $message = "Past parcel {$parcel['consignment_id']}: ".rtrim($message, '. ').'. Nothing was written for this order.';
                }

                $this->record($import, $order['order_ref'], SalesOrderImportOrder::FAILED, Str::limit($message, 480, ''), null, $order);
                $failed++;

                continue;
            }

            if ($parcel !== null) {
                $this->record($import, $order['order_ref'], SalesOrderImportOrder::CREATED, Str::limit(self::parcelSummary($parcel), 480, ''), (int) $salesOrder->id, $order);
                $created++;

                continue;
            }

            $message = null;

            if ($autoApprove) {
                try {
                    $this->approve->handle($salesOrder->fresh(), $by);
                } catch (Throwable $e) {
                    $message = Str::limit('Waiting for approval: '.$e->getMessage(), 480, '');
                }
            }

            if ($salesOrder->fresh()->approvalRequest?->isPending()) {
                $waiting++;
            }

            $this->record($import, $order['order_ref'], SalesOrderImportOrder::CREATED, $message, (int) $salesOrder->id, $order);
            $created++;
        }

        $import->forceFill(['orders_created' => $created, 'orders_skipped' => $skipped, 'orders_failed' => $failed])->save();

        if ($waiting > 0) {
            app(NotifyApprovers::class)->handle(
                businessId: $businessId,
                permission: 'sales.orders.approve',
                summary: "{$waiting} shop order(s) imported from {$import->file_name} wait for approval",
                excludeUserId: $autoApprove ? null : $by->id,
            );
        }

        return $import->fresh('orders');
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, MoneyAccount>  $moneyAccounts
     */
    private function createOne(array $order, SalesOrderImport $import, int $businessId, User $by, array $moneyAccounts): SalesOrder
    {
        $customer = $this->customer($order, $businessId);
        $store = $order['store'];
        $method = $order['advance_method'];
        $account = $method === null ? null : ($moneyAccounts[$method] ?? null);
        /** @var Money $advance */
        $advance = $order['advance'];

        $notes = array_filter([
            $order['note'],
            $order['alt_phone'] !== null ? "Alternative phone {$order['alt_phone']}." : null,
            "Shop order {$order['order_ref']} ({$order['channel']}), imported from {$import->file_name}.",
            // Rule #3 (14 Oct 2026): the discount as imported, kept for audit:
            // the whole order measured the counter's way, and who it passed.
            isset($order['discount_given']) && $order['discount_given']->minor > 0
                ? "Imported discount {$order['discount_given']->format()} off {$order['discount_goods']->format()} of goods at list price (line discounts ".Money::sum(array_map(fn (array $l) => $l['discount'], $order['lines']))->format().", order discount {$order['order_discount']->format()}, the rest price cuts below the list), accepted on the authority of {$by->name} (the counter's discount cap)."
                : null,
            $advance->minor > 0 && $account === null
                ? "Advance {$advance->format()} by {$method}".($order['advance_reference'] ? " (ref {$order['advance_reference']})" : '').' NOT recorded as a receipt: no money account was chosen for it.'
                : null,
        ]);

        $salesOrder = $this->create->handle(
            customer: $customer,
            lines: array_map(fn (array $line) => [
                'product' => $line['product'] instanceof Product ? $line['product'] : Product::query()->findOrFail($line['product']),
                'quantity' => $line['quantity'],
                'unit_price' => $line['override'] ? $line['unit_price']->toTaka() : null,
                'discount' => $line['discount']->toTaka(),
                'price_note' => $line['override']
                    ? 'Imported from shop (list price '.($line['list_price']?->format() ?? 'none').')'
                    : null,
            ], $order['lines']),
            createdBy: $by,
            // A past parcel's order holds and sends from the warehouse it left.
            warehouse: ($order['parcel'] ?? null)['warehouse'] ?? null,
            orderedAt: $order['order_date'],
            note: implode(' ', $notes),
            origin: new SalesOrderOrigin(
                // ⭐ B18 (25 Oct 2026): the sheet's facebook / whatsapp / phone / other
                // are channels of their own now, no longer folded into Internal.
                channel: SalesChannel::tryFrom((string) $order['channel']) ?? SalesChannel::Internal,
                storeId: $store?->id,
                shopReference: $order['order_ref'],
                priceGroupId: $store?->price_group_id,
                deliveryCharge: $order['delivery_charge']?->toTaka(),
                paymentMethod: $order['payment_method'],
                destination: $order['destination'],
                pricesAsChannel: $store !== null,
                orderDiscount: $order['order_discount']->toTaka(),
            ),
            weightGrams: $order['weight_grams'],
            brandId: $order['brand_id'] === null ? null : (int) $order['brand_id'],
            selfApprove: false,
            notifyApprovers: false,
            // ⭐ Owner, 17 Oct 2026: the shop's note is the customer's own
            // instruction ("call before delivery"), so it also travels to the
            // pick list, the challan and the rider.
            specialInstructions: $order['note'],
        );

        // ⭐ B18 (R92): an imported shop order waits for its confirmation call,
        // like a typed one, and shows on the "To confirm" list.
        $salesOrder->forceFill(['confirmation_status' => $salesOrder->effectiveChannel()->needsConfirmation() ? OrderConfirmation::Waiting : OrderConfirmation::Confirmed])->save();

        if ($advance->minor > 0 && $account !== null) {
            $this->payments->handle(
                order: $salesOrder,
                moneyAccount: $account,
                receivedOn: $order['order_date'],
                amount: $advance->toTaka(),
                createdBy: $by,
                reference: $order['advance_reference'] !== null ? mb_substr($order['advance_reference'], 0, 255) : null,
                note: "Advance for shop order {$order['order_ref']} ({$method})",
            );
        }

        return $salesOrder;
    }

    /**
     * Past parcels first, by the day they left (a stable sort, so one day's
     * parcels keep the file's order), then every other order as in the file.
     *
     * @param  array<string, array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    public static function inApplyOrder(array $orders): array
    {
        $orders = array_values($orders);
        $parcels = array_values(array_filter($orders, fn (array $o) => ($o['parcel'] ?? null) !== null));
        $others = array_values(array_filter($orders, fn (array $o) => ($o['parcel'] ?? null) === null));

        // ⚠ usort is stable since PHP 8.0.
        usort($parcels, fn (array $a, array $b) => strcmp($a['parcel']['sent_on'], $b['parcel']['sent_on']));

        return [...$parcels, ...$others];
    }

    /**
     * ⭐ The rest of a past parcel's history, inside the order's transaction:
     * approved by the person importing (whatever the auto-approve box says),
     * confirmed, the full order sent on a challan dated the day it left,
     * booked under the courier's real consignment id for what was still owed,
     * and Delivered on the day with what was collected (invoice and receipt
     * into the courier's account, RecordConsignmentOutcome). Every step is the
     * screen's own action with its own guards; any refusal throws and the
     * whole order is undone.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $parcel
     * @param  array<string, MoneyAccount>  $moneyAccounts
     */
    private function sendPastParcel(SalesOrder $salesOrder, array $order, array $parcel, SalesOrderImport $import, User $by, array $moneyAccounts): void
    {
        /** @var Money $advance */
        $advance = $order['advance'];

        // ⚠ The courier collected only what the advance left. With no account
        // for the advance there is no receipt for it, and the invoice would ask
        // the courier for the whole order.
        if ($advance->minor > 0 && ! isset($moneyAccounts[$order['advance_method']])) {
            throw new RuntimeException("The advance of {$advance->format()} by {$order['advance_method']} has no money account chosen, so it cannot be set against the parcel. Choose the account it went into and import again.");
        }

        // Plan-time rule, asked again at the moment of writing.
        if (($refusal = PlanShopOrderImport::pastParcelRefusal($by)) !== null) {
            throw new RuntimeException($refusal);
        }

        $this->approve->handle($salesOrder->fresh(), $by);
        $salesOrder->refresh();

        if (! $salesOrder->isDeliverable()) {
            throw new RuntimeException("Order {$salesOrder->order_number} needs another approval before it can be sent, so it cannot be imported as a past parcel.");
        }

        $salesOrder->forceFill([
            'confirmation_status' => OrderConfirmation::Confirmed,
            'confirmed_by' => $by->id,
            'confirmed_at' => Carbon::parse($order['order_date']),
            'confirmation_note' => "Past parcel, imported from {$import->file_name}.",
        ])->save();

        /** @var Warehouse $warehouse */
        $warehouse = $parcel['warehouse'];
        $courier = $parcel['courier'];
        $memo = "{$courier->name} consignment {$parcel['consignment_id']}";

        $delivery = $this->deliveries->handle(
            customer: $salesOrder->customer,
            warehouse: $warehouse->fresh(),
            lines: $salesOrder->lines()->with('product')->get()->map(fn (SalesOrderLine $line) => [
                'product' => $line->product,
                'quantity' => (string) $line->quantity_ordered,
                'orderLine' => $line,
            ])->all(),
            createdBy: $by,
            // A bare day: RecordDelivery reads it as the end of that day.
            deliveredAt: $parcel['sent_on'],
            note: "Past parcel {$memo}, imported from {$import->file_name}.",
        );

        // What the ERP itself says the courier should collect: the invoice it
        // would raise, less the advance. It must be what the sheet says.
        $due = DeliveryBilling::previewDue($delivery, $this->prices);
        /** @var Money $collected */
        $collected = $parcel['collected'];

        if ($due === null || $due->minor !== $collected->minor) {
            throw new RuntimeException('The ERP works out '.($due?->format() ?? 'no price')." still owed on this parcel, but the sheet says {$collected->format()} was collected.");
        }

        $consignment = $this->consignments->handle(
            delivery: $delivery,
            courier: $courier->fresh(),
            consignmentNumber: $parcel['consignment_id'],
            codAmount: $due->toTaka(),
            createdBy: $by,
            declaredWeightGrams: $order['weight_grams'],
        );

        $this->outcomes->handle(
            consignment: $consignment,
            outcome: ConsignmentStatus::Delivered,
            on: $parcel['delivered_on'],
            collectedAmount: $collected->toTaka(),
            lines: [],
            recordedBy: $by,
            note: "Past parcel, imported from {$import->file_name}.",
        );
    }

    /** @param array<string, mixed> $parcel */
    private static function parcelSummary(array $parcel): string
    {
        return "Past parcel {$parcel['courier']->name} {$parcel['consignment_id']}: sent ".Carbon::parse($parcel['sent_on'])->format('d/m/Y')
            .', delivered '.Carbon::parse($parcel['delivered_on'])->format('d/m/Y').", collected {$parcel['collected']->format()}.";
    }

    /**
     * The website's rule (ConfirmHqSalesOrderDraft::identify): the first active,
     * non-system customer of the business with this phone; otherwise a new
     * Retail customer. An existing customer is never rewritten.
     *
     * @param  array<string, mixed>  $order
     */
    private function customer(array $order, int $businessId): Customer
    {
        $existing = Customer::query()->withoutGlobalScopes()
            ->where('business_id', $businessId)->where('phone_normalized', $order['phone'])
            ->where('is_active', true)->where('is_system', false)->orderBy('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        return Customer::create([
            'business_id' => $businessId,
            'name' => mb_substr((string) $order['customer_name'], 0, 255),
            'type' => CustomerType::Retail,
            'phone' => $order['phone'],
            'address' => $order['destination']->oneLineAddress(),
            'price_group_id' => null,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed>|null $order */
    private function record(SalesOrderImport $import, string $ref, string $outcome, ?string $message, ?int $orderId, ?array $order = null): void
    {
        SalesOrderImportOrder::create([
            'business_id' => $import->business_id,
            'sales_order_import_id' => $import->id,
            'order_ref' => $ref,
            'sales_order_id' => $orderId,
            'brand_id' => $order['brand_id'] ?? null,
            'brand_name' => $order['brand_name'] ?? null,
            'source_channel' => $order['channel'] ?? null,
            'outcome' => $outcome,
            'message' => $message,
        ]);
    }
}
