<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Support\AssetQrCode;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\Code128;
use App\Modules\Sales\Couriers\Booking\ParcelDraft;
use App\Modules\Sales\Couriers\Jobs\BookParcelsInBulk;
use App\Modules\Sales\Couriers\Locations\CourierDestination;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierBulkRun;
use App\Modules\Sales\Couriers\Models\CourierBulkRunItem;
use App\Modules\Sales\Couriers\Support\ParcelPresenter;
use App\Modules\Sales\Couriers\Support\ParcelsToBook;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderAddress;
use App\Modules\Sales\Support\OrderBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Sales > Parcels to book (Joy, 11 Oct 2026): approved orders waiting for a
 * courier, booked in one click, with labels to print.
 *
 * ⚠ Technical authority (ChatGPT risk 11): `couriers.parcels.book` beside
 * `sales.sell`, on the routes. Nothing here posts money; the challan posts its
 * cost of goods exactly as it does from the order screen.
 */
class CourierBulkBookingController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = app(BusinessContext::class)->id();
        $accounts = ParcelPresenter::bookableAccounts($businessId);
        $inFlight = ParcelsToBook::inFlightOrderIds()->flip();
        $brands = $this->brandNames();
        $places = [];

        $rows = ParcelsToBook::orders()->map(function (SalesOrder $order) use ($accounts, $businessId, $inFlight, $brands, &$places) {
            $plan = ParcelsToBook::plan($order);
            // ⭐ The order screen's own choice for this order's brand: the
            // preselected account is the default, and an account kept for the
            // other brand carries its reason.
            $choices = collect(ParcelsToBook::accountChoices($order, $accounts, $businessId));
            $defaultId = $choices->firstWhere('preselected', true)['id'] ?? null;
            $address = $order->address;
            $open = ParcelsToBook::openBooking($order);
            $due = Money::ofMinor(max(0, Money::ofTaka($order->agreedTotal())->minor - max(0, $order->advanceHeld()->minor)));

            $destinations = [];

            foreach ($accounts as $account) {
                $code = $account->definition()->code();
                $key = $code.'|'.$address?->district.'|'.$address?->thana.'|'.$address?->area;
                $places[$key] ??= CourierDestination::resolve($code, $address?->district, $address?->thana, $address?->area);
                $resolved = $places[$key];
                $choice = $choices->firstWhere('id', $account->id);
                $destinations[$account->id] = $choice !== null && ! $choice['available']
                    ? (string) $choice['unavailable_reason']
                    : ($resolved instanceof Unresolved ? $resolved->message : null);
            }

            $brandId = ParcelsToBook::orderBrandId($order);

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'shop_reference' => $order->shop_reference,
                'ordered_at' => $order->ordered_at?->toDateString(),
                'customer' => $order->customer?->name,
                'recipient' => $address?->recipient_name,
                'phone' => $address?->recipient_phone,
                'place' => collect([$address?->area, $address?->thana, $address?->district])->filter()->implode(', '),
                'brand' => $brandId === null ? null : ($brands[$brandId] ?? null),
                'kind' => $plan['kind'],
                'delivery_number' => $plan['delivery']?->delivery_number,
                'weight_grams' => $order->effectiveWeightGrams(),
                'cod' => (int) ceil($due->minor / 100),
                'default_account_id' => $defaultId,
                // account id => why the courier cannot take it (null = it can)
                'destinations' => $destinations,
                'in_flight' => $inFlight->has($order->id),
                'open_booking' => $open !== null,
            ];
        })->values();

        $fix = $rows->filter(function (array $row) {
            $accountId = $row['default_account_id'];

            return $accountId !== null && ($row['destinations'][$accountId] ?? null) !== null;
        })->map(fn (array $row) => [
            'id' => $row['id'],
            'order_number' => $row['order_number'],
            'place' => $row['place'],
            'message' => $row['destinations'][$row['default_account_id']],
        ])->values();

        return Inertia::render('sales/parcels-to-book/index', [
            'orders' => $rows,
            'fix_addresses' => $fix,
            'accounts' => $this->accountOptions($accounts, $businessId),
            'warehouses' => Warehouse::query()->operational()->where('is_active', true)->saleable()->orderBy('name')->get(['id', 'name', 'code']),
            'runs' => CourierBulkRun::query()->with('createdBy:id,name')->latest('id')->limit(8)->get()->map(fn (CourierBulkRun $run) => $this->runSummary($run))->values(),
            'can_manage_locations' => $request->user()->can('couriers.settings.manage'),
            'max_orders' => (int) config('couriers.bulk_max_orders', 200),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $max = (int) config('couriers.bulk_max_orders', 200);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'order_ids.*' => ['integer', 'distinct'],
            'courier_account_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ], [], ['order_ids' => 'orders']);

        $businessId = app(BusinessContext::class)->id();
        $accounts = ParcelPresenter::bookableAccounts($businessId);

        if (isset($data['courier_account_id']) && $accounts->firstWhere('id', (int) $data['courier_account_id']) === null) {
            throw ValidationException::withMessages(['courier_account_id' => 'That courier account cannot book for this business. Choose another, or "Default for the brand".']);
        }

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages(['courier_account_id' => 'No courier account can book for this business yet. An Owner links one in Settings > Couriers.']);
        }

        $warehouse = null;

        if (isset($data['warehouse_id'])) {
            $warehouse = Warehouse::query()->operational()->where('is_active', true)->saleable()->find((int) $data['warehouse_id']);

            if ($warehouse === null) {
                throw ValidationException::withMessages(['warehouse_id' => 'Choose an active warehouse of this business.']);
            }
        }

        $ids = array_map('intval', $data['order_ids']);
        $orders = SalesOrder::query()->whereIn('id', $ids)->get(['id', 'order_number', 'warehouse_id'])->keyBy('id');

        if ($orders->count() !== count($ids)) {
            throw ValidationException::withMessages(['order_ids' => 'Some of those orders cannot be found. Reload the page.']);
        }

        $busy = ParcelsToBook::inFlightOrderIds()->intersect($ids);

        if ($busy->isNotEmpty()) {
            throw ValidationException::withMessages([
                'order_ids' => 'Already being booked: '.$orders->only($busy->all())->pluck('order_number')->implode(', ').'. Wait for that run to finish.',
            ]);
        }

        if ($warehouse === null && $orders->contains(fn (SalesOrder $o) => $o->warehouse_id === null) && Warehouse::query()->operational()->where('is_active', true)->saleable()->count() !== 1) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose the warehouse the parcels leave from.']);
        }

        $warehouse ??= Warehouse::query()->operational()->where('is_active', true)->saleable()->count() === 1 ? Warehouse::query()->operational()->where('is_active', true)->saleable()->first() : null;

        $run = CourierBulkRun::create([
            'business_id' => $businessId,
            'courier_account_id' => isset($data['courier_account_id']) ? (int) $data['courier_account_id'] : null,
            'warehouse_id' => $warehouse?->id,
            'status' => CourierBulkRun::QUEUED,
            'total' => count($ids),
            'created_by' => $request->user()->id,
        ]);

        foreach ($ids as $id) {
            CourierBulkRunItem::create([
                'business_id' => $businessId,
                'courier_bulk_run_id' => $run->id,
                'sales_order_id' => $id,
                'status' => CourierBulkRunItem::PENDING,
            ]);
        }

        BookParcelsInBulk::dispatch($businessId, $run->id, (int) $request->user()->id);

        return redirect()->route('sales.parcels-to-book.runs.show', $run)->with('success', count($ids).' order(s) are being booked. This page fills in as each one is answered.');
    }

    public function show(Request $request, CourierBulkRun $run): Response
    {
        return Inertia::render('sales/parcels-to-book/run', [
            'run' => $this->runDetail($run),
            'can_manage_locations' => $request->user()->can('couriers.settings.manage'),
        ]);
    }

    /** The same as the page, for a screen that polls while the socket is down. */
    public function progress(CourierBulkRun $run): JsonResponse
    {
        return response()->json($this->runDetail($run));
    }

    public function labels(Request $request): Response
    {
        $data = $request->validate([
            'run' => ['nullable', 'integer'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'per_page' => ['nullable', 'in:2,4'],
        ]);

        $ids = collect($data['ids'] ?? [])->map(fn ($id) => (int) $id);

        if (isset($data['run'])) {
            $run = CourierBulkRun::query()->findOrFail((int) $data['run']);
            $ids = $ids->merge(CourierBulkRunItem::query()->where('courier_bulk_run_id', $run->id)->whereNotNull('consignment_id')->pluck('consignment_id'));
        }

        $consignments = Consignment::query()
            ->with(['delivery.lines.orderLine.order.address', 'courierAccount.provider'])
            ->whereIn('id', $ids->unique()->all())->orderBy('id')->get();

        $brands = $this->brandNames();
        $business = Business::query()->find(app(BusinessContext::class)->id());

        $labels = $consignments->map(function (Consignment $consignment) use ($brands) {
            $order = $consignment->delivery?->lines->map(fn ($line) => $line->orderLine?->order)->filter()->first();
            $address = $order?->address;
            $number = (string) ($consignment->provider_consignment_id ?: $consignment->consignment_number);
            $brandId = $order === null ? null : ParcelsToBook::orderBrandId($order);

            try {
                $barcode = Code128::svg($number, 70, 12);
            } catch (Throwable) {
                $barcode = null;
            }

            return [
                'id' => $consignment->id,
                'number' => $number,
                'qr' => AssetQrCode::svg($number, 160),
                'barcode' => $barcode,
                'courier' => $consignment->courierAccount?->label,
                'recipient' => $address?->recipient_name,
                'phone' => $address?->recipient_phone,
                'address' => $address === null ? null : $this->addressText($address),
                'cod' => Money::ofTaka((string) $consignment->cod_amount)->format(),
                'brand' => $brandId === null ? null : ($brands[$brandId] ?? null),
                'order_number' => $order?->order_number,
                'shop_reference' => $order?->shop_reference,
                'challan' => $consignment->delivery?->delivery_number,
                'weight_grams' => $consignment->declared_weight_grams,
                'contents' => $consignment->delivery === null ? null : ParcelDraft::describe($consignment->delivery),
            ];
        })->values();

        return Inertia::render('sales/parcels-to-book/labels', [
            'labels' => $labels,
            'per_page' => (int) ($data['per_page'] ?? 4),
            'sender' => $business?->name,
        ]);
    }

    /** @return array<string, mixed> */
    private function runDetail(CourierBulkRun $run): array
    {
        $run->load(['items.order:id,order_number,shop_reference', 'items.order.address', 'items.delivery:id,delivery_number', 'items.account.provider', 'account', 'warehouse:id,name', 'createdBy:id,name']);

        return [
            ...$this->runSummary($run),
            'items' => $run->items->sortBy('id')->map(fn (CourierBulkRunItem $item) => [
                'id' => $item->id,
                'status' => $item->status,
                'message' => $item->message,
                'order_id' => $item->sales_order_id,
                'order_number' => $item->order?->order_number,
                'shop_reference' => $item->order?->shop_reference,
                'recipient' => $item->order?->address?->recipient_name,
                'place' => collect([$item->order?->address?->thana, $item->order?->address?->district])->filter()->implode(', '),
                'challan' => $item->delivery?->delivery_number,
                'account' => $item->account?->label,
                'consignment_id' => $item->consignment_id,
                'consignment_number' => $item->consignment_number,
                'fee_quoted' => $item->fee_quoted,
                'cod_amount' => $item->cod_amount,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function runSummary(CourierBulkRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'total' => $run->total,
            'done' => $run->done,
            'booked' => $run->booked,
            'refused' => $run->refused,
            'unknown' => $run->unknown,
            'fix_address' => $run->fix_address,
            'error' => $run->error,
            'account' => $run->account?->label,
            'warehouse' => $run->warehouse?->name,
            'by' => $run->createdBy?->name,
            'at' => $run->created_at?->format('Y-m-d H:i'),
            'finished' => $run->isFinished(),
        ];
    }

    /**
     * @param  Collection<int, CourierAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function accountOptions(Collection $accounts, int $businessId): array
    {
        $defaults = CourierAccountBusiness::query()->where('business_id', $businessId)->where('is_default', true)->pluck('courier_account_id')->map(fn ($id) => (int) $id);

        return $accounts->map(fn (CourierAccount $account) => [
            'id' => $account->id,
            'label' => $account->label,
            'provider_name' => $account->definition()->name(),
            'store_name' => $account->linkFor($businessId)?->provider_store_name,
            'is_default' => $defaults->contains($account->id),
        ])->values()->all();
    }

    /** @return array<int, string> */
    private function brandNames(): array
    {
        return OrderBrand::names(app(BusinessContext::class)->id());
    }

    private function addressText(SalesOrderAddress $address): string
    {
        return collect([$address->address_line, $address->landmark, $address->area, $address->thana, $address->district])
            ->map(fn (?string $part) => trim((string) $part))->filter(fn (string $part) => $part !== '')->implode(', ');
    }
}
