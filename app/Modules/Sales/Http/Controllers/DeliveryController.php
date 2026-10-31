<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\BusinessIdentity;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Corrections\DeliveryActionPolicy;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Support\DeliveredBatches;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\ReservedStockPass;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delivery challans - goods out ahead of the invoice.
 *
 * ⚠ The distributor half of the same engine the counter uses. A challan raised
 * here sits in Goods Delivered Not Invoiced until somebody bills it, and that
 * balance is the "out but not billed" report.
 */
class DeliveryController extends Controller
{
    public function index(Request $request, DeliveryActionPolicy $policy): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];
        // ⭐ Joy, 11 Oct 2026: by the brand of the order it came from.
        $businessId = (int) app(BusinessContext::class)->id();
        $brand = OrderBrand::filter($request->query('brand'), $businessId);
        $filters['brand'] = OrderBrand::filterValue($brand);
        $brandNames = OrderBrand::names($businessId);

        $deliveries = Delivery::query()
            ->tap(fn ($query) => OrderBrand::onDeliveries($query, $brand))
            ->with(['customer:id,name,type', 'warehouse:id,name', 'lines'])
            ->when($filters['search'], fn ($query, $search) => $query->where(
                fn ($q) => $q->where('delivery_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"))
            ))
            // ⚠⚠ Filtered in SQL, BEFORE paginate: the badge's own rule below,
            // asked of the database. It used to drop fully invoiced challans
            // from the twenty already paginated, so "Not fully invoiced" showed
            // only what happened to be among the latest twenty (audit #12,
            // 21 Sep 2026) - and this is the list month-end billing starts from.
            ->when($filters['status'] === 'uninvoiced', fn ($query) => $query->whereNull('reversed_at')->whereHas(
                'lines',
                fn ($line) => $line->whereColumn('quantity_invoiced', '<', 'quantity'),
            ))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->paginate(PerPage::from())
            ->withQueryString();

        $brandOf = OrderBrand::forDeliveries(collect($deliveries->items())->pluck('id'));
        $canReturn = $request->user()->can('sales.returns.record');

        $deliveries = $deliveries
            ->through(fn (Delivery $delivery) => array_merge($delivery->toArray(), [
                'brand' => isset($brandOf[$delivery->id]) ? ($brandNames[$brandOf[$delivery->id]] ?? null) : null,
                // ⚠ The Dhaka date. The datetime serialises in UTC and the list
                // sliced it, so a challan written before 6 am read as yesterday's.
                'delivered_at' => $delivery->delivered_at?->toDateString(),
                // ⚠ Fully invoiced means every line is billed. Partly billed is a
                // normal state, not an error, and it is exactly what the GDNI
                // balance reports.
                'fully_invoiced' => $delivery->lines->every(
                    fn ($line) => (float) $line->quantity_invoiced >= (float) $line->quantity
                ),
                // ⭐ Corrections wave 1: Reverse, as the server allows it here.
                'reversed' => $delivery->isReversed(),
                'reversal' => $delivery->reversalSummary(),
                'actions' => $policy->actionsFor($delivery, request()->user())->toArray()['actions'],
                // ⭐ B20 (Joy, 24 Oct 2026): a return starts from the challan
                // too, while anything on it can still come back. A staff
                // purchase never does (the office order; RecordSalesReturn).
                'can_return' => $canReturn && ! $delivery->isReversed()
                    && $delivery->customer?->type !== CustomerType::Staff
                    && $delivery->lines->contains(fn ($line) => (float) $line->quantityReturnable() > 0),
            ]));

        return Inertia::render('sales/deliveries/index', [
            'deliveries' => $deliveries,
            'filters' => $filters,
            'brands' => OrderBrand::options($businessId),
            // ⭐ B7: a Not for sale (quarantine) warehouse is never offered.
            'warehouses' => Warehouse::query()->operational()->where('is_active', true)->saleable()
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            // ⭐ B4 (24 Oct 2026): a direct challan offers only what is for sale.
            // ⭐ B22 (24 Oct 2026): a size reads "Miniket Rice - 25 kg".
            'products' => Product::withFamilyNames(Product::query()->where('is_active', true)->stocked()->sellable()
                ->orderBy('name')->get(['id', 'name', 'sku'])),
            'customers' => Customer::query()->listable()->active()->orderBy('name')->get(['id', 'name']),
            'reversalReasons' => Reason::query()->where('context', 'reversal')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The challan that travels with the goods.
     *
     * ⚠⚠ THE DELIVERY HAD NO VIEW OF ANY KIND before this: an engine, an index
     * row and a journal, but nothing anybody could hand to the person receiving
     * the goods. A challan is not a nicety in this trade, it is what gets signed
     * at the door.
     *
     * ⚠ NO MONEY ON IT, deliberately. A challan says what left the warehouse; a
     * delivery line has no price column at all, and printing one would invite
     * the recipient to treat it as a bill.
     */
    public function print(Delivery $delivery): Response
    {
        $delivery->load(['customer', 'warehouse:id,code,name', 'lines.product:id,name,sku', 'lines.orderLine.order:id,order_number,special_instructions']);

        // Owner, 17 Oct 2026: the batch number and expiry each line took.
        $batches = DeliveredBatches::forLines($delivery->lines->pluck('id'));
        $delivery->lines->each(fn ($line) => $line->setAttribute('batches', $batches[$line->id] ?? []));

        return Inertia::render('sales/deliveries/print', [
            // ⚠ Re-check F39, 17 Oct 2026: the model went over whole, so the
            // challan printed "Date: 2026-10-17T07:30:58.000000Z", the UTC
            // serialisation, which also reads as yesterday before 6 am Dhaka.
            // Sent as the Dhaka date, the same as the challan list; the page
            // writes it out in words.
            'delivery' => array_merge($delivery->toArray(), [
                'delivered_at' => $delivery->delivered_at?->toDateString(),
            ]),
            'issuer' => BusinessIdentity::forCurrent()->toArray(),
        ]);
    }

    public function store(Request $request, RecordDelivery $action): RedirectResponse
    {
        $data = $request->validate([
            // ⚠ SAL-006 (audit 14 Oct 2026): every id is checked inside this business.
            // A bare exists: let another business's id through to a scoped lookup,
            // which gave a blank 500 or stored a foreign id, instead of a sentence.
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('business_id', app(BusinessContext::class)->id())],
            // ⚠ Bug hunt 16 Oct 2026: a future date was a 500 page (EffectiveDate
            // refuses it with an exception no screen catches). Refused here, as
            // a sentence under the date.
            'delivered_at' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // ⭐ B17 (25 Oct 2026, Q4 = A): a Manager or the Owner takes stock
            // held for a confirmed order, with a reason.
            'take_reserved_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'delivered_at.before_or_equal' => 'A challan cannot be dated in the future. Goods leave on the day they leave.',
        ]);

        $products = Product::query()
            ->whereIn('id', collect($data['lines'])->pluck('product_id'))
            ->get()->keyBy('id');

        // ⚠ C-SLS-E1, same three refusals as the order-traced challan.
        try {
            $delivery = $action->handle(
                customer: Customer::findOrFail($data['customer_id']),
                warehouse: Warehouse::findOrFail($data['warehouse_id']),
                lines: collect($data['lines'])->map(fn (array $line) => [
                    'product' => $products[$line['product_id']],
                    'quantity' => (string) $line['quantity'],
                ])->all(),
                createdBy: $request->user(),
                deliveredAt: $data['delivered_at'] ?? null,
                note: $data['note'] ?? null,
                takeReserved: trim((string) ($data['take_reserved_reason'] ?? '')) === '' ? null : new ReservedStockPass($request->user(), (string) $data['take_reserved_reason']),
            );
        } catch (InsufficientStockException|PeriodClosedException|MappingNotFound $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()->with('success', "Challan {$delivery->delivery_number} recorded.");
    }
}
