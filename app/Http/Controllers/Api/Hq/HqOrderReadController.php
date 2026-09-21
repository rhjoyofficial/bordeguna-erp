<?php

namespace App\Http\Controllers\Api\Hq;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Commerce\Support\Availability;
use App\Modules\Commerce\Support\CartRules;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The reads HQ's Phase 4 needs before it may ever take an order (HQ docs/79, docs/81 C.5).
 *
 * ⛔ READS ONLY. Nothing here creates, changes or cancels an order, a customer or a price, and no
 * route under `order-reads/` writes anything; the customer check is a POST only so the phone number
 * stays out of access logs. The future order write is a separate capability with its own gates.
 *
 * ⚠ Nothing here decides anything either. Every answer is the ERP's own engine asked the ERP's own
 * question: the price is `ResolveSellingPrice`, the per-store verdict is `CartRules` and
 * `Availability`, the customer match is the one `PlaceWebsiteOrder::identify` makes. A second
 * opinion about money or stock written here would be the drift those classes exist to prevent.
 *
 * ⚠ Every body is built from an explicit field list and nothing else, so a column added to a model
 * later does not start leaving the ERP on its own. Cost, credit limit, payment terms, contact
 * details and notes are absent by construction, whatever abilities the token holds.
 */
final class HqOrderReadController extends Controller
{
    public const CONTRACT = 'hq-order-reads.v1';

    /** The only keys a product row may carry. */
    public const PRODUCT_FIELDS = [
        'id', 'sku', 'barcode', 'name', 'type', 'is_active', 'orderable', 'unit', 'brand', 'category',
        'availability_override', 'lead_time_days',
    ];

    public function products(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'inventory.view');
        $data = $request->validate([
            'filter' => ['nullable', 'array:q,sku,id,barcode,brand,status'],
            'filter.q' => ['nullable', 'string', 'max:100'],
            'filter.sku' => ['nullable', 'string', 'max:64'],
            'filter.id' => ['nullable', 'integer', 'min:1'],
            'filter.barcode' => ['nullable', 'string', 'max:64'],
            'filter.brand' => ['nullable', 'string', 'max:64'],
            'filter.status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filter = $data['filter'] ?? [];
        $status = $filter['status'] ?? 'active';

        $rows = $this->productQuery()
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->when(isset($filter['q']), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$filter['q'].'%')
                ->orWhere('sku', 'like', '%'.$filter['q'].'%')))
            ->when(isset($filter['sku']), fn ($q) => $q->where('sku', $filter['sku']))
            ->when(isset($filter['id']), fn ($q) => $q->whereKey((int) $filter['id']))
            ->when(isset($filter['barcode']), fn ($q) => $q->where('barcode', $filter['barcode']))
            ->when(isset($filter['brand']), fn ($q) => $q->whereHas('brand', fn ($b) => $b->where('code', $filter['brand'])))
            ->orderBy('sku')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 50))->withQueryString();
        $rows->through(fn (Product $p) => $this->productRow($p));

        return response()->json([
            'data' => $rows->items(),
            'meta' => $this->meta($business, [
                'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
                'orderable_means' => 'Only that a sales order accepts this type of product: a service cannot be ordered. '
                    .'Price, activity and availability are separate reads.',
            ]),
            'links' => ['next' => $rows->nextPageUrl(), 'prev' => $rows->previousPageUrl()],
        ]);
    }

    public function sku(Request $request, int $business, string $sku): JsonResponse
    {
        $this->requireAbility($request, 'inventory.view');
        $product = $this->productQuery()->where('sku', $sku)->first();
        if (! $product) {
            return $this->error('not_found', "No product with SKU {$sku}.", 404);
        }

        return $this->ok($business, $this->productRow($product) + [
            'variant_model' => 'none',
            'variant_note' => 'The ERP has no variants: a SKU names exactly one product in a business.',
        ]);
    }

    public function price(Request $request, int $business, string $sku, ResolveSellingPrice $engine): JsonResponse
    {
        $this->requireAbility($request, 'sales.view');
        $data = $request->validate([
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'store' => ['nullable', 'string', 'max:64', 'prohibits:customer_id'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $product = Product::query()->where('sku', $sku)->first();
        if (! $product) {
            return $this->error('not_found', "No product with SKU {$sku}.", 404);
        }

        $customer = null;
        if (isset($data['customer_id'])) {
            // ⚠ A customer's own price is their agreement with the business: the customer ability too.
            $this->requireAbility($request, 'sales.customers.view');
            $customer = Customer::query()->whereKey((int) $data['customer_id'])->where('is_system', false)->first();
            if (! $customer) {
                return $this->error('unknown_customer', 'Unknown customer.', 404);
            }
        }

        $store = null;
        if (isset($data['store'])) {
            $store = Store::query()->with('priceGroup:id,code')->where('key', $data['store'])->first();
            if (! $store) {
                return $this->error('unknown_store', "No store with key {$data['store']}.", 404);
            }
        }

        $quantity = $this->decimal($data['quantity'] ?? '1');
        $on = isset($data['on']) ? Carbon::parse($data['on']) : Carbon::now();

        // The one engine, asked the way each caller asks it: a store as its own price group
        // (CataloguePublishing::priceFor), a customer as themselves, nobody as the default tier.
        $resolved = $engine->find(
            product: $product,
            customer: $customer,
            quantity: $quantity,
            on: $on,
            priceGroupId: $store?->price_group_id,
        );
        if ($resolved === null) {
            return $this->error('no_price_in_force', "No price is in force for SKU {$sku} at quantity {$quantity} on {$on->toDateString()}.", 404);
        }
        $rule = $resolved->rule->loadMissing('priceGroup:id,code,name,prices_include_tax');

        return $this->ok($business, [
            'sku' => $product->sku,
            'product_id' => $product->id,
            'unit_price' => (string) $resolved->unitPrice,
            'currency' => 'BDT',
            'basis' => 'selling_price',
            'quantity' => $quantity,
            'on' => $on->toDateString(),
            'priced_as' => $customer ? 'customer' : ($store ? 'store' : 'nobody'),
            'store' => $store ? ['key' => $store->key, 'price_group' => $store->priceGroup?->code] : null,
            'mrp' => null,
            'mrp_note' => 'The ERP keeps no MRP on a product or in the price list. A production batch may carry the MRP printed '
                .'on its label, which prices nothing.',
            'price_source' => [
                'rule_id' => $rule->id,
                'tier' => $resolved->tier(),
                'price_group' => $rule->priceGroup ? [
                    'code' => $rule->priceGroup->code,
                    'name' => $rule->priceGroup->name,
                    'prices_include_tax' => (bool) $rule->priceGroup->prices_include_tax,
                ] : null,
                'customer_specific' => $resolved->tier() === 'customer',
                'min_quantity' => (string) $rule->min_quantity,
                'effective_from' => $rule->effective_from?->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString(),
                'is_discount' => (bool) $rule->is_discount,
                'discount_label' => $rule->discount_label,
                'explanation' => $resolved->explain(),
            ],
            'promotions_applied_to_sales_orders' => [],
            'promotions_note' => 'Buy X Get Y promotions are applied only by a counter sale. A sales order carries none.',
            'tier_note' => $customer || $store ? null
                : 'Nobody is named, so only the standard selling price can apply: a group or customer price is not knowable yet.',
        ]);
    }

    public function availability(Request $request, int $business, string $sku): JsonResponse
    {
        $this->requireAbility($request, 'inventory.view');
        $data = $request->validate(['store' => ['nullable', 'string', 'max:64']]);

        $product = Product::query()->with('unitOfMeasure:id,abbreviation')->where('sku', $sku)->first();
        if (! $product) {
            return $this->error('not_found', "No product with SKU {$sku}.", 404);
        }

        $store = null;
        if (isset($data['store'])) {
            $store = Store::query()->where('key', $data['store'])->first();
            if (! $store) {
                return $this->error('unknown_store', "No store with key {$data['store']}.", 404);
            }
        }

        // Where the stock sits is an inventory-report fact, as on the stock endpoint.
        $canSeeWarehouses = $request->user()->tokenCan('inventory.reports.view');
        $warehouses = $canSeeWarehouses
            ? StockBatch::query()->where('stock_batches.status', StockBatchStatus::Active)->where('stock_batches.product_id', $product->id)
                ->join('warehouses', 'warehouses.id', '=', 'stock_batches.warehouse_id')
                ->groupBy('warehouses.id', 'warehouses.code', 'warehouses.name')
                ->selectRaw('warehouses.code as code, warehouses.name as name, SUM(stock_batches.current_quantity) as on_hand')
                ->orderBy('warehouses.code')->get()
                ->map(fn ($row) => ['code' => $row->code, 'name' => $row->name, 'on_hand' => $this->decimal($row->on_hand)])->values()->all()
            : null;

        $verdict = null;
        if ($store) {
            $decision = Availability::decide($store, $product);
            $refusal = CartRules::refusalFor($store, $product);
            $verdict = [
                'key' => $store->key,
                'policy' => Availability::policyFor($store, $product)->value,
                'published' => StoreProduct::query()->where('store_id', $store->id)->where('product_id', $product->id)->whereNotNull('published_at')->exists(),
                'has_price' => CataloguePublishing::hasPrice($store, $product),
                'sellable' => $refusal === null,
                'made_to_order' => $refusal === null && $decision->madeToOrder,
                'message' => $refusal ?? $decision->message(),
            ];
        }

        return $this->ok($business, [
            'sku' => $product->sku,
            'product_id' => $product->id,
            'stock' => [
                'on_hand' => $this->decimal($product->currentStockQuantity()),
                'unit' => $product->unitOfMeasure?->abbreviation,
                'warehouses' => $warehouses,
                'warehouses_withheld' => ! $canSeeWarehouses,
            ],
            'availability' => [
                'decided_per_store' => true,
                'product_is_active' => (bool) $product->is_active,
                'product_type_is_stocked' => $product->type->isStocked(),
                'availability_override' => $product->availability_override?->value,
                'verdict_needs_store' => $store === null,
                'store' => $verdict,
            ],
        ]);
    }

    /**
     * Whether a phone names a customer, answered the way the website's checkout would pick one
     * (`PlaceWebsiteOrder::identify`): the first active, non-system customer of the business with
     * that normalised number. It creates nobody; the checkout would, and says so here.
     */
    public function customerEligibility(Request $request, int $business): JsonResponse
    {
        $this->requireAbility($request, 'sales.customers.view');
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        $key = PhoneIdentity::normalize($data['phone']);
        $matches = $key === null ? collect() : Customer::query()
            ->where('business_id', $business)
            ->where('phone_normalized', $key)
            // ⛔ Never the seeded walk-in row, exactly as the checkout refuses it.
            ->where('is_system', false)
            ->orderBy('id')
            ->get(['id', 'name', 'is_active']);
        $active = $matches->where('is_active', true)->values();
        $picked = $active->first();

        return $this->ok($business, [
            'phone_matchable' => $key !== null,
            'phone_normalized' => $key,
            'customer_exists' => $matches->isNotEmpty(),
            'match' => $picked ? ['id' => $picked->id, 'display_name' => $picked->name, 'is_active' => true] : null,
            'active_matches' => $active->count(),
            'inactive_matches' => $matches->count() - $active->count(),
            'would_create_new_customer' => $picked === null,
            'refusal_rules_the_erp_has' => ['inactive_customer'],
            'fields_the_erp_does_not_have' => ['blocked', 'cod_eligible', 'credit_hold'],
            'credit_limit_enforced' => false,
            'notes' => [
                'A sales order refuses an inactive customer. The website skips an inactive match and creates a new customer instead.',
                'A household may share a number, so more than one active customer can hold it; the first by id is the one picked.',
                'The ERP keeps no blocked, cash-on-delivery or credit-hold flag, and shows a credit limit without enforcing it.',
            ],
        ]);
    }

    // ---- helpers ----------------------------------------------------------------------------

    private function productQuery()
    {
        return Product::query()->with(['brand:id,code,name', 'category:id,name', 'unitOfMeasure:id,name,abbreviation']);
    }

    private function productRow(Product $p): array
    {
        return Arr::only([
            'id' => $p->id,
            'sku' => $p->sku,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'type' => $p->type->value,
            'is_active' => (bool) $p->is_active,
            'orderable' => $p->type->isStocked(),
            'unit' => $p->unitOfMeasure ? ['name' => $p->unitOfMeasure->name, 'abbreviation' => $p->unitOfMeasure->abbreviation] : null,
            'brand' => $p->brand ? ['code' => $p->brand->code, 'name' => $p->brand->name] : null,
            'category' => $p->category ? ['name' => $p->category->name] : null,
            'availability_override' => $p->availability_override?->value,
            'lead_time_days' => $p->lead_time_days,
        ], self::PRODUCT_FIELDS);
    }

    /**
     * A decimal as its shortest exact string: `5.0000` and `5.0` both read `5`. Quantities are
     * decimal(15,4), so four places are the whole domain and nothing is rounded away.
     */
    private function decimal(mixed $value): string
    {
        $text = number_format((float) $value, 4, '.', '');
        $trimmed = rtrim(rtrim($text, '0'), '.');

        return $trimmed === '' || $trimmed === '-0' ? '0' : $trimmed;
    }

    private function requireAbility(Request $request, string $ability): void
    {
        if (! $request->user()->tokenCan($ability)) {
            abort(response()->json(['error' => ['code' => 'forbidden', 'message' => "Token lacks ability {$ability}."]], 403));
        }
    }

    private function meta(int $business, array $extra = []): array
    {
        return ['business_id' => $business, 'contract' => self::CONTRACT] + $extra + ['as_of' => now()->toIso8601String()];
    }

    private function ok(int $business, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $this->meta($business)]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
