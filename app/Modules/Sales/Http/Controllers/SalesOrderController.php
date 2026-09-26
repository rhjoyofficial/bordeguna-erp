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
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\PickPlan;
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
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\MoneyAccountRefusal;
use App\Modules\Sales\Support\OrderNextSteps;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        // ⭐ 26 Sep 2026 (Q21): a field officer (sales.orders.record without
        // sales.reports.view) sees the orders he took and nobody else's.
        $ownOnly = ! $request->user()->can('sales.reports.view');

        $orders = SalesOrder::query()
            ->when($ownOnly, fn ($query) => $query->where('created_by', $request->user()->id))
            ->with(['customer:id,name', 'warehouse:id,name', 'approvalRequest'])
            ->withSum('lines as total_ordered', 'quantity_ordered')
            ->withSum('lines as total_delivered', 'quantity_delivered')
            // ⚠ THE SHOP REFERENCE IS SEARCHABLE TOO, and it has to be: a
            // customer ringing up quotes `BNC-20260912-X7JWX2YT` and has never
            // seen `SO-FY2026-0001`. A number a customer holds that staff
            // cannot search for is a number that costs a support call.
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shop_reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('sales/orders/index', [
            'orders' => $orders,
            'filters' => $filters,
            'own_only' => $ownOnly,
            // A CRM lead's "New order" link names the customer (26 Sep 2026).
            'preselect_customer' => $request->integer('customer') ?: null,
            // ⚠ C-SO-2 (Joy): NO reservation. This report is the honest
            // substitute - what is promised against what is on the shelf, per
            // product, for a human deciding whether to take the next order.
            // Nothing anywhere enforces it.
            'committed' => $this->committedVersusOnHand(),
            // ⭐ Phone and type ride along (21 Sep 2026): the picker finds a
            // customer by phone, and the quick "New customer" warns on a number
            // that is already somebody's.
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name', 'phone', 'type', 'credit_limit']),
            'warehouses' => Warehouse::query()->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'products' => Product::query()->where('is_active', true)->stocked()
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure_id'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'unit' => $product->unitOfMeasure?->abbreviation,
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
                $onHand = (float) $product->currentStockQuantity();

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
            'lines.product:id,name,sku',
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
            ->with(['lines.product:id,name,sku', 'lines.orderLine', 'customer', 'consignment.courier:id,name', 'consignment.invoice:id,invoice_number', 'consignment.salesReturn:id,return_number'])
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->get();

        $challans = $deliveries
            ->map(function (Delivery $delivery) use ($resolver) {
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
                    'lines' => $delivery->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'product' => $line->product?->name,
                        'sku' => $line->product?->sku,
                        'quantity' => $line->quantityUninvoiced(),
                    ])->values(),
                    'consignment' => $consignment === null ? null : [
                        'id' => $consignment->id,
                        'courier' => $consignment->courier?->name,
                        'consignment_number' => $consignment->consignment_number,
                        'cod_amount' => $consignment->codAmount()->toTaka(),
                        'status' => $consignment->status->value,
                        'status_label' => $consignment->status->label(),
                        'outcome_on' => $consignment->outcome_on?->toDateString(),
                        'collected_amount' => $consignment->collectedAmount()?->toTaka(),
                        'invoice' => $consignment->invoice?->only(['id', 'invoice_number']),
                        'sales_return' => $consignment->salesReturn?->only(['id', 'return_number']),
                    ],
                ];
            });

        return Inertia::render('sales/orders/show', [
            // ⚠ The order's dates are Dhaka dates. Handed over inside the model
            // they serialise as UTC timestamps, so the page printed
            // "2026-09-12T18:00:00.000000Z" for an order placed on the 13th.
            'order' => array_merge($order->toArray(), [
                'ordered_at' => $order->ordered_at?->toDateString(),
                'expected_date' => $order->expected_date?->toDateString(),
            ]),
            'challans' => $challans,
            // ⭐ What is left to do, in order (21 Sep 2026): the page leads with it.
            'next_steps' => OrderNextSteps::for($order, $deliveries, $user),
            'couriers' => MoneyAccount::query()->active()->where('type', MoneyAccountType::Courier->value)
                ->orderBy('name')->get(['id', 'name']),
            'can_record_outcome' => $user->can('sales.sell') && $user->can('sales.receipt.record') && $user->can('sales.returns.record'),
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
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
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
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
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
        ]);

        $product = Product::findOrFail($data['product_id']);
        $customer = Customer::findOrFail($data['customer_id']);
        $resolved = $resolver->find($product, $customer, (string) ($data['quantity'] ?: '1'));

        return response()->json([
            'unit_price' => $resolved?->unitPrice,
            'explanation' => $resolved?->explain(),
            'refusal' => $resolved === null
                ? "No price is set for {$customer->name} on {$product->name}. Set it under Sales setup, Selling prices, or this line cannot be ordered."
                : null,
        ]);
    }

    public function store(Request $request, CreateSalesOrder $action): RedirectResponse
    {
        $this->authorize('sales.orders.record');

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'ordered_at' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // ⚠ A manual price override, taken verbatim and marked as such on
            // the line - the same seam the counter has. Absent, the SERVER
            // resolves the price at the order date.
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

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

        // ⚠ C-SLS-E1. The server resolves every price that was not overridden,
        // so a product the price file never reached refuses the order here. The
        // exception names the product; say so on the form.
        try {
            $order = $action->handle(
                customer: Customer::findOrFail($data['customer_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                    'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
                ])->all(),
                createdBy: $request->user(),
                warehouse: isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null,
                orderedAt: $data['ordered_at'] ?? null,
                expectedDate: $data['expected_date'] ?? null,
                note: $data['note'] ?? null,
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
     * ⚠ C-SO-3, the "show" half: the exposure a customer would stand at,
     * answered by the server for the create form the moment a customer is
     * picked. Blocks nothing.
     */
    public function exposure(Request $request, CustomerExposure $action): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'delivered_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer', 'exists:sales_order_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

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
