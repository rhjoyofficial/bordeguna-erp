<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Foundation\Actions\RejectApprovable;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\PickPlan;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CheckCustomerCredit;
use App\Modules\Sales\Actions\CloseSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\CustomerExposure;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordOrderPayment;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Actions\SendPaidOrderForApproval;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Support\ParcelPresenter;
use App\Modules\Sales\Couriers\Support\ParcelsToBook;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\DoorResolution;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\CostVisibility;
use App\Modules\Sales\Support\CourierSays;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\DiscountCap;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\OrderNextSteps;
use App\Modules\Sales\Support\OwnerPriceVisibility;
use App\Modules\Sales\Support\ParcelWeight;
use App\Modules\Sales\Support\SalesOrderDestination;
use App\Modules\Sales\Support\SalesOrderOrigin;
use App\Support\BdGeo;
use App\Support\Dashboards\Widgets\CourierWidget;
use App\Support\Documents\RelatedDocuments;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The order book - what is promised, to whom, and how much of it has left.
 *
 * ⚠ A screen over CreateSalesOrder and RecordDelivery, not a parallel system.
 * Prices are resolved by the SERVER at store time and copied onto the lines;
 * this controller never computes one.
 */
class SalesOrderController extends Controller
{
    /**
     * The order book's queues (Sales redesign, 15 Oct 2026): where an order
     * stands between being taken and being paid for, as chips with counts.
     * Each queue is a server-side filter on the same query the list runs, so
     * a chip's count is exactly the rows it shows.
     */
    public const QUEUES = ['approval', 'dispatch', 'moving', 'problem', 'delivered'];

    public function index(Request $request): Response
    {
        $queue = $request->string('queue')->toString();
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'queue' => in_array($queue, self::QUEUES, true) ? $queue : '',
        ];
        $businessId = (int) app(BusinessContext::class)->id();
        // ⭐ Joy, 11 Oct 2026: orders by brand ("none" = taken before brands
        // were recorded). A brand of another business reads as all brands.
        $brand = OrderBrand::filter($request->query('brand'), $businessId);
        $filters['brand'] = OrderBrand::filterValue($brand);

        // ⭐ 26 Sep 2026 (Q21): a field officer (sales.orders.record without
        // sales.reports.view) sees the orders he took and nobody else's.
        $ownOnly = ! $request->user()->can('sales.reports.view');

        // What the chips count and the list shows: the same base, so the
        // numbers on the chips are the rows a click brings (search and brand
        // narrow both; the queue and the status narrow the list only).
        $base = fn () => SalesOrder::query()
            ->when($ownOnly, fn ($query) => $query->where('created_by', $request->user()->id))
            ->tap(fn ($query) => OrderBrand::onOrders($query, $brand))
            // ⚠ THE SHOP REFERENCE IS SEARCHABLE TOO, and it has to be: a
            // customer ringing up quotes `BNC-20260912-X7JWX2YT` and has never
            // seen `SO-FY2026-0001`. A number a customer holds that staff
            // cannot search for is a number that costs a support call.
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shop_reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ));

        $orders = $base()
            ->with([
                'customer:id,name', 'warehouse:id,name', 'approvalRequest', 'brand:id,code,name',
                // ⭐ "Courier says": the parcel already stored on the order's
                // challan, eager-loaded once for the page (CourierSays).
                'lines:id,sales_order_id',
                'lines.deliveryLines:id,sales_order_line_id,delivery_id',
                'lines.deliveryLines.delivery:id',
                'lines.deliveryLines.delivery.consignment:id,delivery_id,money_account_id,status,courier_status,courier_status_at,outcome_on',
                'lines.deliveryLines.delivery.consignment.courier:id,name',
            ])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->withSum('lines as total_delivered', 'quantity_delivered')
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['queue'], fn ($query, $queue) => self::inQueue($query, $queue))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (SalesOrder $order) {
                $order->setAttribute('courier_says', CourierSays::forOrder($order));
                // The challan lines served their purpose; the page never reads them.
                $order->unsetRelation('lines');

                return $order;
            });

        $queues = ['all' => $base()->count()];

        foreach (self::QUEUES as $key) {
            $queues[$key] = self::inQueue($base(), $key)->count();
        }

        return Inertia::render('sales/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            'queues' => $queues,
            'own_only' => $ownOnly,
            // ⭐ The bulk-booking nudge (15 Oct 2026): approved orders no courier
            // has yet, for whoever may book them in one run.
            'to_book' => $request->user()->can('sales.sell') && $request->user()->can('couriers.parcels.book')
                ? ParcelsToBook::orders()->count()
                : null,
            // A CRM lead's "New order" link names the customer (26 Sep 2026).
            'preselect_customer' => $request->integer('customer') ?: null,
            // ⭐ The brand picker (required when the business has brands), and
            // the brand this person last took an order for, pre-chosen.
            'brands' => OrderBrand::options($businessId),
            'last_brand_id' => $this->lastBrandId($request, $businessId),
            // ⭐ F24 (practice run, 17 Oct 2026): and the warehouse this person
            // last took an order for, if it is still in use. Else the default.
            'last_warehouse_id' => $this->lastWarehouseId($request, $businessId),
            // ⚠ C-SO-2 (Joy): NO reservation. This report is the honest
            // substitute - what is promised against what is on the shelf, per
            // product, for a human deciding whether to take the next order.
            // Nothing anywhere enforces it.
            'committed' => $this->committedVersusOnHand(),
            // ⭐ Phone and type ride along (21 Sep 2026): the picker finds a
            // customer by phone, and the quick "New customer" warns on a number
            // that is already somebody's.
            // ⭐ F36: the address rides along so the order's delivery address
            // starts as the customer's.
            'customers' => Customer::query()->listable()->active()->orderBy('name')->get(['id', 'name', 'phone', 'type', 'credit_limit', 'address']),
            // F36: the district picker of the delivery address (the ERP's list,
            // the one the courier booking checks against).
            'districts' => array_column(BdGeo::districts(), 'name'),
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'products' => Product::query()->where('is_active', true)->stocked()->sellable()
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure_id', 'weight_grams', 'brand_id'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    // Practice run, 18 Oct 2026: with Bionic-Garden chosen the picker
                    // still offered LaLaDia products. The form filters by this.
                    'brand_id' => $product->brand_id,
                    'unit' => $product->unitOfMeasure?->abbreviation,
                    // ⭐ Courier hub slice 3: so the form can add up the parcel
                    // weight as lines are typed. Null = weight missing.
                    'weight_grams' => $product->weight_grams,
                ]),
            'can_create' => $request->user()->can('sales.orders.record'),
            'can_override_price' => $request->user()->can('sales.price.override'),
            // A customer can be added from the order form by whoever may add one
            // on the Customers screen, with the same fields that decide the price.
            'can_add_customer' => $request->user()->can('sales.customers.manage'),
            'customer_types' => collect(CustomerType::cases())
                ->reject(fn (CustomerType $type) => $type === CustomerType::WalkIn)
                ->map(fn (CustomerType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'price_groups' => PriceGroup::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * One queue of the order book, as a filter on the orders query.
     *
     *   approval   open, and the approval request is pending or sent back
     *   dispatch   approved and no parcel of it is with a courier yet: not fully
     *              challaned, or challaned for a delivery address nobody has booked
     *   moving     a parcel of it is booked and the courier reports no problem
     *   problem    a parcel of it is booked and the courier reports a problem, it is
     *              coming back, or its booking answer was lost
     *   delivered  a parcel's outcome is recorded as delivered, or the order went out
     *              fully without a courier (counter, own van: no delivery address)
     *
     * ⚠ An order's own "delivered" status means the goods LEFT the warehouse on
     * a challan, not that the customer has them; the courier's answer, or the
     * absence of a courier, says that.
     *
     * The same words the Sales overview's flow strip and the dashboard
     * widgets use (CourierWidget::PROBLEM_STATUSES), so the chip and the card
     * agree.
     */
    public static function inQueue(Builder $query, string $queue): Builder
    {
        $open = [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered];
        $problems = array_map(fn (CourierStatus $s) => $s->value, [...CourierWidget::PROBLEM_STATUSES, CourierStatus::Returning]);
        $parcel = 'lines.deliveryLines.delivery.consignment';

        return match ($queue) {
            'approval' => $query->whereIn('status', $open)
                ->whereHas('approvalRequest', fn ($a) => $a->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Returned])),
            'dispatch' => $query
                ->whereHas('approvalRequest', fn ($a) => $a->where('status', ApprovalStatus::Approved))
                ->whereDoesntHave($parcel)
                ->where(fn ($q) => $q->whereIn('status', $open)
                    ->orWhere(fn ($w) => $w->where('status', SalesOrderStatus::Delivered)->whereHas('address'))),
            'moving' => $query->whereHas($parcel, fn ($c) => $c
                ->where('status', ConsignmentStatus::Booked->value)
                ->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', $problems))),
            'problem' => $query->where(fn ($q) => $q
                ->whereHas($parcel, fn ($c) => $c->where('status', ConsignmentStatus::Booked->value)->whereIn('courier_status', $problems))
                ->orWhereHas('lines.deliveryLines.delivery', fn ($d) => $d->whereIn('id', CourierBookingRequest::query()
                    ->withoutGlobalScopes()->select('delivery_id')->where('status', CourierBookingRequest::UNKNOWN)->whereNull('resolved_at')))),
            'delivered' => $query->where(fn ($q) => $q
                ->whereHas($parcel, fn ($c) => $c->whereIn('status', [ConsignmentStatus::Delivered->value, ConsignmentStatus::PartiallyDelivered->value]))
                ->orWhere(fn ($w) => $w->whereIn('status', [SalesOrderStatus::Delivered, SalesOrderStatus::Closed])
                    ->whereDoesntHave($parcel)->whereDoesntHave('address'))),
            default => $query,
        };
    }

    /**
     * The undelivered promise per product, against what the shelves hold.
     *
     * ⚠ Only APPROVED, still-open orders count - a pending order is not yet a
     * commitment, and closed/cancelled remainders stopped being owed. Derived
     * fresh on every load, stored nowhere; a reservation table that must be
     * maintained is exactly what C-SO-2 declined.
     *
     * @return array<int, array{product_id: int, product_name: string, product_sku: string, committed: string, on_hand: string, short: bool}>
     */
    private function committedVersusOnHand(): array
    {
        $lines = SalesOrderLine::query()
            ->whereHas('order', fn ($q) => $q
                ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
                ->whereHas('approvalRequest', fn ($a) => $a->where('status', ApprovalStatus::Approved)))
            ->with('product:id,name,sku')
            ->get();

        return $lines
            ->groupBy('product_id')
            ->map(function ($productLines) {
                $committed = $productLines->sum(fn (SalesOrderLine $line) => (float) $line->quantityRemaining());
                $product = $productLines->first()->product;
                // ⚠ 14 Oct 2026 (audit #14): sellable, not book: an expired
                // batch cannot be delivered, so it must not hide a shortage.
                $onHand = (float) $product->sellableStockQuantity();

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'committed' => number_format($committed, 4, '.', ''),
                    'on_hand' => number_format($onHand, 4, '.', ''),
                    'short' => $committed > $onHand,
                ];
            })
            ->filter(fn (array $row) => (float) $row['committed'] > 0)
            ->values()
            ->all();
    }

    /**
     * The sheet the warehouse walks around with.
     *
     * ⚠⚠ WHAT IS LEFT TO PICK, not what was ordered. An order part-delivered
     * last week must not send somebody back for the whole quantity, and the
     * line's own `quantityRemaining()` is what the delivery screen will accept
     * anyway.
     *
     * ⚠ The batches come from `PickPlan`, which reads the same FIFO scope the
     * ledger consumes, so the shelf and the books agree about which goods left.
     * It reserves nothing: between printing and picking somebody may sell the
     * same box at the counter, and the sheet says so.
     */
    /**
     * ⭐ 26 Sep 2026 (Q21): without sales.reports.view a person opens only the
     * orders they took. Checked on the document, not only on the list, so a
     * guessed order number is a 403 and not somebody else's dealer.
     */
    private function assertMayOpen(Request $request, SalesOrder $order): void
    {
        if ($request->user()->can('sales.reports.view') || (int) $order->created_by === (int) $request->user()->id) {
            return;
        }

        abort(403, 'That order was taken by somebody else.');
    }

    public function pickList(SalesOrder $order): Response
    {
        $this->assertMayOpen(request(), $order);

        $order->load([
            'customer',
            'warehouse:id,name,code',
            'lines.product:id,name,sku,unit_of_measure_id',
            'lines.product.unitOfMeasure:id,abbreviation',
        ]);

        $warehouse = $order->warehouse;

        $lines = $order->lines->map(function ($line) use ($warehouse) {
            $toPick = (float) $line->quantityRemaining();

            $plan = $warehouse === null
                ? ['on_hand' => 0.0, 'shortfall' => $toPick, 'batches' => []]
                : PickPlan::for($line->product, $warehouse, $toPick);

            return [
                'id' => $line->id,
                'product' => $line->product?->name,
                'sku' => $line->product?->sku,
                'unit' => $line->product?->unitOfMeasure?->abbreviation,
                'description' => $line->description,
                'ordered' => (float) $line->quantity_ordered,
                'delivered' => (float) $line->quantity_delivered,
                'to_pick' => $toPick,
                'on_hand' => $plan['on_hand'],
                'shortfall' => $plan['shortfall'],
                'batches' => $plan['batches'],
            ];
        })->values();

        return Inertia::render('sales/orders/pick-list', [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'ordered_at' => $order->ordered_at?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
                'status' => $order->status->value,
                'note' => $order->note,
                'special_instructions' => $order->special_instructions,
                'customer' => $order->customer?->only(['id', 'name', 'code', 'address', 'phone']),
                'warehouse' => $order->warehouse?->only(['id', 'name', 'code']),
            ],
            'lines' => $lines,
            // Nothing left to pick is worth saying on the page rather than
            // leaving somebody to add the columns up themselves.
            'nothing_to_pick' => $lines->every(fn (array $line) => $line['to_pick'] <= 0),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function show(Request $request, SalesOrder $order, ResolveSellingPrice $resolver): Response
    {
        $this->assertMayOpen($request, $order);

        $order->load([
            'customer',
            'brand:id,code,name',
            'warehouse:id,name',
            // ⚠ Website Phase 2, slice 2.4. A website order lands in this
            // screen's approval queue, and the person confirming it needs the
            // recipient's phone number and the destination - without them the
            // confirmation call the design depends on cannot be made. Both are
            // null on an order somebody typed in, and the page renders nothing.
            'store:id,name',
            'address',
            'createdBy:id,name',
            'approvalRequest.requestedBy:id,name',
            'approvalRequest.reviewedBy:id,name',
            // weight_grams: the parcel weight is worked out from it (slice 3).
            'lines.product:id,name,sku,weight_grams',
            'lines.product.unitOfMeasure:id,abbreviation',
            'lines.deliveryLines.delivery:id,delivery_number,delivered_at',
        ]);

        $user = $request->user();

        $deliveryIds = $order->lines->flatMap(fn ($line) => $line->deliveryLines->pluck('delivery_id'))->unique()->values();

        // ⭐ Each challan with what a courier needs: its lines, its parcel, and
        // the amount to collect if it were delivered today. Shaped here, dates
        // as dates, never a model handed to the page.
        $deliveries = Delivery::query()
            ->whereIn('id', $deliveryIds)
            ->with(['lines.product:id,name,sku,weight_grams', 'lines.orderLine.order.lines', 'customer', 'consignment.courier:id,name', 'consignment.invoice:id,invoice_number', 'consignment.salesReturn:id,return_number', 'consignment.courierAccount.provider', 'consignment.shipmentEvents', 'consignment.lossClaim'])
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->get();

        // ⭐ Courier hub slice 4: booking through the courier's API, for whoever
        // may book, and only with accounts that serve this business.
        $canBook = $user->can('sales.sell') && $user->can('couriers.parcels.book');
        $canViewParcels = $user->can('couriers.parcels.view');
        $courierAccounts = $canBook ? ParcelPresenter::bookableAccounts((int) $order->business_id) : collect();
        // ⭐ Joy, 11 Oct 2026: a parcel goes through the courier account that
        // serves the order's brand. Shown for every challan of this order.
        $brandId = $order->brand_id === null ? null : (int) $order->brand_id;

        $challans = $deliveries
            ->map(function (Delivery $delivery) use ($resolver, $canBook, $canViewParcels, $courierAccounts) {
                $consignment = $delivery->consignment;
                $untouched = $delivery->lines->every(fn ($line) => (float) $line->quantity_invoiced <= 0 && (float) $line->quantity_returned <= 0);

                return [
                    'id' => $delivery->id,
                    'delivery_number' => $delivery->delivery_number,
                    'delivered_at' => $delivery->delivered_at->toDateString(),
                    'can_consign' => $consignment === null && $untouched,
                    // ⚠ Less money already paid for the order (SSLCommerz slice
                    // P1): the courier collects what is still owed.
                    'cod_preview' => $consignment === null && $untouched
                        ? DeliveryBilling::previewDue($delivery, $resolver)?->toTaka()
                        : null,
                    // ⭐ Courier hub slice 3: what this challan weighs by the
                    // product weights, and the weight to offer the courier.
                    'weight' => ParcelWeight::forDelivery($delivery)->toArray(),
                    'weight_suggested' => $consignment === null && $untouched ? ParcelWeight::suggestedForDelivery($delivery) : null,
                    'lines' => $delivery->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'product' => $line->product?->name,
                        'sku' => $line->product?->sku,
                        'quantity' => $line->quantityUninvoiced(),
                    ])->values(),
                    'courier_draft' => $consignment === null && $untouched && $courierAccounts->isNotEmpty()
                        ? ParcelPresenter::draft($delivery, $courierAccounts, $resolver)
                        : null,
                    'courier_booking' => $consignment === null && ($canBook || $canViewParcels) ? ParcelPresenter::openBooking($delivery) : null,
                    'consignment' => $consignment === null ? null : [
                        'id' => $consignment->id,
                        'courier_info' => $canViewParcels || $canBook ? ParcelPresenter::courier($consignment) : null,
                        'courier' => $consignment->courier?->name,
                        'consignment_number' => $consignment->consignment_number,
                        'cod_amount' => $consignment->codAmount()->toTaka(),
                        'declared_weight_grams' => $consignment->declared_weight_grams,
                        'declared_weight_auto_grams' => $consignment->declared_weight_auto_grams,
                        'status' => $consignment->status->value,
                        'status_label' => $consignment->status->label(),
                        'outcome_on' => $consignment->outcome_on?->toDateString(),
                        'collected_amount' => $consignment->collectedAmount()?->toTaka(),
                        'invoice' => $consignment->invoice?->only(['id', 'invoice_number']),
                        'sales_return' => $consignment->salesReturn?->only(['id', 'return_number']),
                        // ⭐ Courier hub slice 8.
                        'settlement' => ParcelPresenter::settlement($consignment),
                    ],
                ];
            });

        return Inertia::render('sales/orders/show', [
            // PR5 (18 Oct 2026): what this came from, what came of it, and the journals.
            'related' => RelatedDocuments::for($order, request()->user()),
            // ⚠ The order's dates are Dhaka dates. Handed over inside the model
            // they serialise as UTC timestamps, so the page printed
            // "2026-09-12T18:00:00.000000Z" for an order placed on the 13th.
            'order' => array_merge($order->toArray(), [
                'ordered_at' => $order->ordered_at?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
            ]),
            'challans' => $challans,
            // ⭐ Courier hub slice 3: the parcel weight kept on the order, and
            // what its lines weigh by today's product weights (which items
            // still have none).
            'weight' => [
                'auto_grams' => $order->weight_grams_auto,
                'typed_grams' => $order->weight_grams,
                'effective_grams' => $order->effectiveWeightGrams(),
                'now' => ParcelWeight::forOrder($order)->toArray(),
            ],
            // ⭐ What is left to do, in order (21 Sep 2026): the page leads with it.
            'next_steps' => OrderNextSteps::for($order, $deliveries, $user),
            'couriers' => MoneyAccount::query()->active()->where('type', MoneyAccountType::Courier->value)
                ->orderBy('name')->get(['id', 'name']),
            'courier_accounts' => ParcelPresenter::accountOptions($courierAccounts, (int) $order->business_id, $brandId),
            'can_book_courier' => $canBook,
            'can_view_parcels' => $canViewParcels,
            'can_record_outcome' => $user->can('sales.sell') && $user->can('sales.receipt.record') && $user->can('sales.returns.record'),
            // ⭐ Courier hub slice 8: settling what the courier reports.
            'door_resolutions' => DoorResolution::options(),
            'can_correct_price' => $user->can('sales.price.override'),
            'can_report_loss' => $user->can('sales.sell') && $user->can('sales.returns.record'),
            'can_settle_claims' => $user->can('sales.receipt.record'),
            'books_open_from' => AccountingPeriod::current()?->is_unlocked === false
                ? AccountingPeriod::current()->closed_through->copy()->addDay()->toDateString()
                : null,
            // ⚠ C-SO-3: exposure SHOWN beside the credit limit, enforced by
            // nobody. The human decides.
            'exposure' => app(CustomerExposure::class)->handle($order->customer),
            // ⚠⚠ Enforced since 21 Sep 2026: what approving this order would
            // meet, so the page says it before the button is pressed.
            'credit_refusal' => $order->approvalRequest?->isPending()
                ? app(CheckCustomerCredit::class)->refusalFor($order)?->getMessage()
                : null,
            'can_override_credit' => $user->can('sales.credit.override'),
            'payments' => $this->payments($order),
            // ⚠ Where money can be taken or paid back by hand: never a courier's
            // account, which only a parcel outcome and a payout statement touch,
            // and never one with no ledger head, which the posting refuses.
            'money_accounts' => $user->can('sales.receipt.record') || $user->can('sales.returns.refund')
                ? MoneyAccount::query()->active()->handled()->withLedger()->orderBy('name')->get(['id', 'name', 'type'])
                    ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->label()])
                    ->values()
                : [],
            'can_record_payment' => $user->can('sales.receipt.record'),
            'can_refund' => $user->can('sales.returns.refund'),
            'can_gateway_refund' => $user->can('sales.gateway.refund'),
            'can_approve' => $user->can('sales.orders.approve'),
            'can_deliver' => $user->can('sales.sell'),
            'can_cancel' => $user->can('sales.orders.cancel'),
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
        ]);
    }

    /**
     * Money for this order: what was paid for it, what its invoices took, what
     * it still holds, and what went back (SSLCommerz slice P1). Shaped here,
     * dates as dates.
     *
     * @return array<string, mixed>
     */
    private function payments(SalesOrder $order): array
    {
        $held = $order->advanceHeld();
        $cancelled = $order->status === SalesOrderStatus::Cancelled
            || $order->approvalRequest?->status === ApprovalStatus::Rejected;

        return [
            'agreed_total' => Money::ofTaka($order->agreedTotal())->toTaka(),
            'paid' => $order->paidTowards()->toTaka(),
            'still_to_pay' => $order->stillToPay()->toTaka(),
            'held' => $held->toTaka(),
            // ⚠ Money held by an order nothing more will be billed on is the
            // customer's to have back. Said on the screen, not left to be found.
            'refund_owed' => $held->minor > 0 && $order->isFinishedForBilling() ? $held->toTaka() : null,
            'can_take_payment' => ! $cancelled && $order->stillToPay()->minor > 0,
            'receipts' => $order->advanceReceipts()
                ->with('moneyAccount:id,name', 'allocations.invoice:id,invoice_number')
                ->orderBy('received_on')->orderBy('id')->get()
                ->map(fn (CustomerReceipt $receipt) => [
                    'id' => $receipt->id,
                    'received_on' => $receipt->received_on->toDateString(),
                    'money_account' => $receipt->moneyAccount?->name,
                    'reference' => $receipt->reference,
                    'amount' => $receipt->amountMoney()->toTaka(),
                    'invoices' => $receipt->allocations->map(fn ($allocation) => [
                        'id' => $allocation->sales_invoice_id,
                        'invoice_number' => $allocation->invoice?->invoice_number,
                        'amount' => Money::ofTaka((string) $allocation->amount)->toTaka(),
                    ])->values(),
                ])->values(),
            // ⭐ Every attempt to pay online (SSLCommerz slice P2c), so whoever
            // confirms the order sees a payment the gateway flagged as risky, or
            // one proven but not yet booked, before the parcel leaves.
            'online' => GatewayPayment::query()->where('sales_order_id', $order->id)->orderBy('id')->get()
                ->map(fn (GatewayPayment $attempt) => [
                    'id' => $attempt->id,
                    'tran_id' => $attempt->tran_id,
                    'status' => $attempt->status->value,
                    'status_label' => $attempt->status->label(),
                    'is_live' => $attempt->is_live,
                    'amount' => $attempt->amountMoney()->toTaka(),
                    'amount_paid' => $attempt->amountPaidMoney()?->toTaka(),
                    'fee' => $attempt->status->tookMoney() ? $attempt->fee()->toTaka() : null,
                    'bank_tran_id' => $attempt->bank_tran_id,
                    'card_type' => $attempt->card_type,
                    'risky' => $attempt->isRisky(),
                    'risk_title' => $attempt->risk_title,
                    'problem' => $attempt->problem,
                    'started_at' => $attempt->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    // Slice P4: what this payment can still give back through
                    // SSLCommerz. Null for an attempt that took no booked money.
                    'refundable' => $attempt->status === GatewayPaymentStatus::Paid && filled($attempt->bank_tran_id)
                        ? Money::ofMinor(max(0, ($attempt->amountPaidMoney()?->minor ?? 0) - GatewayRefund::takenFrom($attempt)->minor))->toTaka()
                        : null,
                ])->values(),
            // ⭐ Every refund sent through SSLCommerz (slice P4), finished or not,
            // so a refund still on its way is never mistaken for money still owed.
            'gateway_refunds' => GatewayRefund::query()->where('sales_order_id', $order->id)
                ->with('payment:id,tran_id', 'requestedBy:id,name')
                ->orderBy('id')->get()
                ->map(fn (GatewayRefund $refund) => [
                    'id' => $refund->id,
                    'refund_trans_id' => $refund->refund_trans_id,
                    'tran_id' => $refund->payment?->tran_id,
                    'amount' => $refund->amountMoney()->toTaka(),
                    'remarks' => $refund->remarks,
                    'status' => $refund->status->value,
                    'status_label' => $refund->status->label(),
                    'in_flight' => $refund->status->isInFlight(),
                    'refund_ref_id' => $refund->refund_ref_id,
                    'problem' => $refund->problem,
                    'requested_by' => $refund->requestedBy?->name,
                    'requested_at' => $refund->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    'refunded_at' => $refund->refunded_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                    'last_checked_at' => $refund->last_checked_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                ])->values(),
            'refunding' => GatewayRefund::inFlightFor($order)->toTaka(),
            'refunds' => $order->advanceRefunds()
                ->with('moneyAccount:id,name')
                ->orderBy('refunded_on')->orderBy('id')->get()
                ->map(fn (CustomerRefund $refund) => [
                    'id' => $refund->id,
                    'refunded_on' => $refund->refunded_on->toDateString(),
                    'money_account' => $refund->moneyAccount?->name,
                    'reference' => $refund->reference,
                    'amount' => $refund->amountMoney()->toTaka(),
                ])->values(),
        ];
    }

    /**
     * Money a customer paid for this order by hand - a bKash transfer to the
     * shop's number, cash at the office - before or after its invoice.
     */
    public function payment(Request $request, SalesOrder $order, RecordOrderPayment $action): RedirectResponse
    {
        $data = $request->validate([
            // ⚠ SAL-006 (audit 14 Oct 2026): every id is checked inside this business.
            // A bare exists: let another business's id through to a scoped lookup,
            // which gave a blank 500 or stored a foreign id, instead of a sentence.
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        try {
            $action->handle(
                order: $order,
                moneyAccount: $moneyAccount,
                receivedOn: $data['received_on'],
                amount: (string) $data['amount'],
                createdBy: $request->user(),
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['received_on' => $e->getMessage()]);
        } catch (MappingNotFound $e) {
            throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($moneyAccount, $e)]);
        }

        // ⚠ An online order the customer paid by hand instead (a bKash transfer):
        // now it has money, it goes to be confirmed like one the gateway paid.
        app(SendPaidOrderForApproval::class)->handle($order->fresh(), $request->user());

        return back()->with('success', 'Payment recorded.');
    }

    /**
     * Money paid back out of what this order holds.
     */
    public function refund(Request $request, SalesOrder $order, RecordCustomerRefund $action): RedirectResponse
    {
        $data = $request->validate([
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'refunded_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $moneyAccount = MoneyAccount::findOrFail($data['money_account_id']);

        try {
            $action->forOrder(
                order: $order,
                moneyAccount: $moneyAccount,
                amount: (string) $data['amount'],
                createdBy: $request->user(),
                refundedOn: $data['refunded_on'],
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (PeriodClosedException $e) {
            throw ValidationException::withMessages(['refunded_on' => $e->getMessage()]);
        } catch (MappingNotFound $e) {
            throw ValidationException::withMessages(['money_account_id' => MoneyAccountRefusal::sentence($moneyAccount, $e)]);
        }

        return back()->with('success', 'Refund recorded.');
    }

    /**
     * The price a line would be ordered at, for the order form as it is typed
     * (21 Sep 2026). The same resolver the order runs, so the form shows what
     * the order will say; a product with no price for this customer is named
     * here, before the order is refused for it.
     */
    public function price(Request $request, ResolveSellingPrice $resolver): JsonResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', $businessId)],
            'quantity' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            // ⭐ D-B (18 Oct 2026): an owner line is priced at this warehouse's FIFO batch.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('business_id', $businessId)],
            // ⭐ Joy, 19 Oct 2026: the order's own date, so a back-dated order is
            // shown the price that was in force on the day it was sold, the same
            // date the order itself resolves its prices at.
            'on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $customer = Customer::findOrFail($data['customer_id']);
        $on = isset($data['on']) ? Carbon::parse($data['on']) : null;
        $earlierDay = $on !== null && ! $on->isToday();
        $resolved = $resolver->find($product, $customer, (string) ($data['quantity'] ?: '1'), $on, warehouseId: isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null);
        $seesWhy = ! ($resolved?->isBatchCost() ?? false) || OwnerPriceVisibility::allows($request->user());
        // A past day with no price on file is ordinary for an order entered
        // late; whoever may change prices types what was charged that day.
        $typeIt = $earlierDay && $request->user()->can('sales.price.override')
            ? ' Type the price it was sold at.'
            : '';

        return response()->json([
            'unit_price' => $resolved?->unitPrice,
            'explanation' => $seesWhy ? $resolved?->explain() : null,
            // ⭐ Phase 2 / 1B: "since HH:MM" for the badge on a market line.
            'market_since' => $resolved?->marketSince(),
            'refusal' => $resolved === null
                ? ($product->isMarketPriced()
                    ? ($earlierDay
                        ? "{$product->name} had no confirmed price on {$on->format('d/m/Y')}.{$typeIt}"
                        : "{$product->name} has no confirmed price today, so it cannot be ordered. Receive a lot and confirm today's price, or set one under Sales setup, Selling prices.")
                    : ($earlierDay
                        ? "No price was set for {$customer->name} on {$product->name} on {$on->format('d/m/Y')}.{$typeIt}"
                        : "No price is set for {$customer->name} on {$product->name}. Set it under Sales setup, Selling prices, or this line cannot be ordered."))
                : null,
            // ⭐ F21: the line warns (never blocks) when the agreed price is below
            // what the goods last cost. Only for people who may read costs.
            'last_cost' => CostVisibility::allows($request->user())
                ? (Product::latestUnitCosts([$product->id])[$product->id] ?? null)
                : null,
        ]);
    }

    public function store(Request $request, CreateSalesOrder $action, ResolveSellingPrice $resolver): RedirectResponse
    {
        $this->authorize('sales.orders.record');

        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            // ⭐ Joy, 19 Oct 2026: the order form takes the selling date, so an
            // order taken earlier can be entered afterwards. Never ahead of today.
            'ordered_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expected_date' => ['nullable', 'date', $request->filled('ordered_at') ? 'after_or_equal:ordered_at' : 'nullable'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // ⚠ A manual price override, taken verbatim and marked as such on
            // the line - the same seam the counter has. Absent, the SERVER
            // resolves the price at the order date.
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): the parcel weight typed
            // over the worked-out one. Blank = the worked-out weight. No reason.
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:'.ProductWeight::MAX_GRAMS],
            'brand_id' => ['nullable', 'integer'],
            // ⭐ Owner, 17 Oct 2026: a note per line ("gift wrap") and one for the
            // packer and the rider ("call before delivery").
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'special_instructions' => ['nullable', 'string', 'max:500'],
            // ⭐ F35: what the customer pays for delivery, so the invoice and
            // the courier's COD include it.
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
            // ⭐ F36: where the parcel goes, checked by SalesOrderDestination,
            // the same check the shop's checkout and the courier booking use.
            'address' => ['nullable', 'array'],
            'address.name' => ['nullable', 'string', 'max:120'],
            'address.phone' => ['nullable', 'string', 'max:30'],
            'address.district' => ['nullable', 'string', 'max:100'],
            'address.thana' => ['nullable', 'string', 'max:100'],
            'address.area' => ['nullable', 'string', 'max:150'],
            'address.address_line' => ['nullable', 'string', 'max:255'],
            'address.landmark' => ['nullable', 'string', 'max:255'],
        ], [
            'ordered_at.before_or_equal' => 'An order cannot be dated in the future.',
            'expected_date.after_or_equal' => 'The expected date cannot be before the order date.',
        ]);

        $destination = self::destinationFrom($data['address'] ?? []);
        $deliveryCharge = isset($data['delivery_charge']) && (float) $data['delivery_charge'] > 0
            ? Money::ofTaka((string) $data['delivery_charge'])->toTaka()
            : null;

        // ⭐ Joy, 11 Oct 2026: every order carries its brand, so one is required
        // wherever the business sells under brands. The action checks it is
        // this business's own and still in use.
        $businessId = (int) app(BusinessContext::class)->id();

        if (! isset($data['brand_id']) && OrderBrand::options($businessId, activeOnly: true)->isNotEmpty()) {
            throw ValidationException::withMessages(['brand_id' => 'Choose the brand this order is sold under.']);
        }

        // ⚠⚠ A typed price is a margin decision (21 Sep 2026): whoever may take an
        // order had it for any figure, with nothing to say who chose it. Only a
        // holder of the counter's own override permission may send one now.
        foreach ($data['lines'] as $index => $line) {
            if (isset($line['unit_price']) && ! $request->user()->can('sales.price.override')) {
                throw ValidationException::withMessages([
                    "lines.{$index}.unit_price" => 'You may not change the price. The order takes the price set for this customer.',
                ]);
            }
        }

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        $customer = Customer::findOrFail($data['customer_id']);

        // ⚠⚠ Audit 13 Oct: the per-bill discount cap on the ORDER too. The
        // invoice bills an order line at the order's price as already agreed,
        // so without this a Manager could type 40% off here and approve it
        // themselves. Measured against the list price at the order date, as
        // the quotation does; a product with no list price has no yardstick.
        $orderedOn = isset($data['ordered_at']) ? Carbon::parse($data['ordered_at']) : Carbon::now();
        $capLines = [];

        foreach ($data['lines'] as $line) {
            if (! isset($line['unit_price'])) {
                continue;
            }

            $quantity = (string) $line['quantity'];

            try {
                $listPrice = (string) $resolver->resolve($products[$line['product_id']], $customer, $quantity, $orderedOn, warehouseId: isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null)->unitPrice;
            } catch (NoSellingPriceException) {
                continue;
            }

            $capLines[] = ['quantity' => $quantity, 'unitPrice' => (string) $line['unit_price'], 'discount' => '0', 'listPrice' => $listPrice];
        }

        $overCap = DiscountCap::refusal($request->user(), $businessId, $capLines, '0');

        if ($overCap !== null) {
            throw ValidationException::withMessages(['lines' => $overCap]);
        }

        // ⚠ C-SLS-E1. The server resolves every price that was not overridden,
        // so a product the price file never reached refuses the order here. The
        // exception names the product; say so on the form.
        try {
            $order = $action->handle(
                customer: $customer,
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
                    'description' => $line['description'] ?? null,
                ])->all(),
                createdBy: $request->user(),
                warehouse: isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null,
                orderedAt: $data['ordered_at'] ?? null,
                expectedDate: $data['expected_date'] ?? null,
                note: $data['note'] ?? null,
                weightGrams: isset($data['weight_grams']) ? (int) $data['weight_grams'] : null,
                brandId: isset($data['brand_id']) ? (int) $data['brand_id'] : null,
                // ⚠ An origin only when there is something to carry: a plain
                // order stays exactly as it was (channel unset, no address).
                origin: $destination === null && $deliveryCharge === null ? null : new SalesOrderOrigin(
                    channel: SalesChannel::Internal,
                    deliveryCharge: $deliveryCharge,
                    destination: $destination,
                ),
                specialInstructions: $data['special_instructions'] ?? null,
            );
        } catch (NoSellingPriceException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        // ⚠ Say so when the credit rules held back an approval the person would
        // otherwise have given their own order (21 Sep 2026).
        $held = $order->approvalRequest?->isPending() && $request->user()->can('sales.orders.approve')
            ? app(CheckCustomerCredit::class)->refusalFor($order)?->getMessage()
            : null;

        return redirect()->route('sales.orders.show', $order)
            ->with('success', "Sales order {$order->order_number} recorded.".($held ? " It is waiting for approval: {$held}" : ''));
    }

    /**
     * The brand of this person's own latest order that had one, still a brand
     * in use here: what the order form offers first. Derived, never stored.
     */
    private function lastBrandId(Request $request, int $businessId): ?int
    {
        $id = SalesOrder::query()
            ->where('business_id', $businessId)
            ->where('created_by', $request->user()->id)
            ->whereNotNull('brand_id')
            ->whereHas('brand', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('id')
            ->value('brand_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The stock-dating refusal in the words of the challan form. The batch rule
     * says "Cannot move stock on <when> from batch #N, which was received on
     * <when>"; anything else is passed on as it was.
     */
    private static function challanDateProblem(string $message): string
    {
        if (preg_match('/^Cannot move stock on (\S+) .* received on (\S+) /', $message, $m) === 1) {
            $out = Carbon::parse($m[1])->format('d/m/Y');
            $in = Carbon::parse($m[2])->format('d/m/Y');

            return "The goods for this challan came into stock on {$in}, after {$out}. Date the challan on or after {$in}, or enter the earlier purchase or opening stock first.";
        }

        return $message;
    }

    /**
     * The delivery address typed on the order form, or null when none was
     * started. ⚠ Started means a district, thana or street was given: the
     * recipient's name and phone are filled in from the customer by the
     * form, so on their own they say nothing about a parcel.
     *
     * @param  array<string, mixed>  $input
     */
    private static function destinationFrom(array $input): ?SalesOrderDestination
    {
        $started = collect(['district', 'thana', 'address_line'])
            ->contains(fn (string $key) => trim((string) ($input[$key] ?? '')) !== '');

        if (! $started) {
            return null;
        }

        try {
            return SalesOrderDestination::fromInput($input);
        } catch (ValidationException $e) {
            // The checker names the bare field; the form's fields sit under address.
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["address.{$key}" => $messages])->all()
            );
        }
    }

    private function lastWarehouseId(Request $request, int $businessId): ?int
    {
        $id = SalesOrder::query()
            ->where('business_id', $businessId)
            ->where('created_by', $request->user()->id)
            ->whereNotNull('warehouse_id')
            ->whereHas('warehouse', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('id')
            ->value('warehouse_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * ⚠ C-SO-3, the "show" half: the exposure a customer would stand at,
     * answered by the server for the create form the moment a customer is
     * picked. Blocks nothing.
     */
    public function exposure(Request $request, CustomerExposure $action): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', app(BusinessContext::class)->id())],
        ]);

        return response()->json($action->handle(Customer::findOrFail($data['customer_id'])));
    }

    /*
     * ⚠⚠ THE FOUR BARE BUTTONS: approve, reject, cancel, close short.
     *
     * Each action refuses with a sentence written for a person ("already been
     * reviewed", "has deliveries against it - close it short instead", "waiting
     * for its online payment") and none of them was caught, so every refusal
     * came up as a 500 page (audit #2, 21 Sep 2026). A second approve is the
     * ordinary way it happens: two managers, or one double-click.
     *
     * Caught the way PurchaseOrderController catches its own: a button has no
     * form to hang a field error on, so the sentence goes to the error toast.
     * ⚠ A database error is a RuntimeException too (PDOException), and its SQL
     * must never be read out in a toast, so it is let through to the error page.
     */
    public function approve(Request $request, SalesOrder $order, ApproveSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.approve');

        // ⚠ Only an Owner's written reason sets the credit rules aside, and
        // ApproveSalesOrder checks the permission itself (21 Sep 2026).
        $data = $request->validate(['credit_override_reason' => ['nullable', 'string', 'max:500']]);

        try {
            $action->handle($order, $request->user(), $data['credit_override_reason'] ?? null);
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sales order approved.');
    }

    public function reject(Request $request, SalesOrder $order, RejectApprovable $action): RedirectResponse
    {
        $this->authorize('sales.orders.approve');

        $data = $request->validate(['review_note' => ['nullable', 'string']]);

        try {
            $action->handle($order, $request->user(), $data['review_note'] ?? null);
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sales order rejected.');
    }

    public function cancel(SalesOrder $order, CancelSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.cancel');

        try {
            $action->handle($order);
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sales order cancelled.');
    }

    public function close(Request $request, SalesOrder $order, CloseSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.cancel');

        // ⚠ The one of the four that takes input. An empty reason is refused on
        // `reason`, which the order page now renders (and its dialog no longer
        // lets through empty); a refusal about the order itself goes to the
        // toast like the other three.
        $data = $request->validate(
            ['reason' => ['required', 'string']],
            ['reason.required' => 'Say why the rest of the order is not being taken.'],
        );

        try {
            $action->handle($order, $request->user(), $data['reason']);
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sales order closed short.');
    }

    /**
     * Goods out against this order - the challan raised from the order screen,
     * where the person fulfilling it is standing.
     */
    public function deliver(Request $request, SalesOrder $order, RecordDelivery $action): RedirectResponse
    {
        $this->authorize('sales.sell');

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            // ⚠ Bug hunt 16 Oct 2026: a future date was a 500 page.
            'delivered_at' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer', Rule::exists('sales_order_lines', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ], [
            'delivered_at.before_or_equal' => 'A challan cannot be dated in the future. Goods leave on the day they leave.',
        ]);

        // ⭐ Joy, 19 Oct 2026: back-dated orders bring back-dated challans, and a
        // challan dated before its own order is a typing mistake, never a sale.
        if (isset($data['delivered_at']) && Carbon::parse($data['delivered_at'])->startOfDay()->lt($order->ordered_at->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'delivered_at' => "A challan cannot be dated before its order. {$order->order_number} was ordered on {$order->ordered_at->format('d/m/Y')}.",
            ]);
        }

        $order->load('lines.product');

        $kept = collect($data['lines'])
            ->filter(fn (array $entry) => (float) $entry['quantity'] > 0)
            ->map(function (array $entry) use ($order) {
                $orderLine = $order->lines->firstWhere('id', $entry['sales_order_line_id']);

                return $orderLine === null ? null : [
                    'product' => $orderLine->product,
                    'quantity' => (string) $entry['quantity'],
                    'orderLine' => $orderLine,
                ];
            })
            ->filter();

        $lines = $kept->values()->all();

        // Which row of the form each line the action sees came from. The action
        // numbers only the lines with a quantity, so without this "line 0" of an
        // over-delivery is the first line with a quantity, not the first row.
        $formRow = $kept->keys()->values()->all();

        // ⚠ C-SLS-E1. Delivering more than the warehouse holds, or into a month
        // somebody has already reported on, is refused by the action. Both are
        // ordinary mistakes a picker makes and neither deserves a blank screen.
        try {
            $delivery = $action->handle(
                customer: $order->customer,
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: $lines,
                createdBy: $request->user(),
                deliveredAt: $data['delivered_at'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (InsufficientStockException|PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (InvalidArgumentException $e) {
            // ⚠ 19 Oct 2026: a back-dated challan taking stock that arrived
            // later was a 500 page (EffectiveDate::assertNotBefore). It is an
            // ordinary entry mistake: say which day the goods came in.
            throw ValidationException::withMessages(['delivered_at' => self::challanDateProblem($e->getMessage())]);
        } catch (ValidationException $e) {
            // ⚠⚠ OVER-DELIVERY WAS INVISIBLE. RecordDelivery refuses it on
            // `lines.N.orderLine`, the form rendered only `lines`, and so the
            // commonest warehouse mistake - typing 50 where 20 remain - made
            // Record challan look like it did nothing (audit #1, 21 Sep 2026).
            // Every per-line refusal is put back on the form row it came from,
            // as `lines.{row}.quantity`, which the form renders under that line.
            throw ValidationException::withMessages(collect($e->errors())
                ->mapWithKeys(function (array $messages, string $key) use ($formRow) {
                    if (preg_match('/^lines\.(\d+)\./', $key, $match) === 1) {
                        return ['lines.'.($formRow[(int) $match[1]] ?? $match[1]).'.quantity' => $messages];
                    }

                    return [$key => $messages];
                })->all());
        }

        return back()->with('success', "Challan {$delivery->delivery_number} recorded against {$order->order_number}.");
    }
}
