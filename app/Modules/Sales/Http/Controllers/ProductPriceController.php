<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Enums\PricingMode;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\MarketPrice;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\ProductPriceHistory;
use App\Modules\Sales\Support\ProductPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What things sell for.
 *
 * ⚠⚠ A PRICE IS NEVER EDITED, IT IS SUPERSEDED. Changing the number on an
 * existing row rewrites history, and an invoice raised in July then cannot be
 * explained in September because the rule that produced it no longer exists.
 * Closing the old row and opening a new one is the same principle the account
 * mappings use, and it is why the resolver can answer "why this price?".
 */
class ProductPriceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'product' => $request->integer('product'),
        ];

        $prices = ProductPrice::query()
            ->with(['product:id,name,sku', 'customer:id,name', 'priceGroup:id,code,name'])
            ->when($filters['product'], fn ($query, $id) => $query->where('product_id', $id))
            ->when($filters['search'], fn ($query, $search) => $query->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            // ⚠ Dhaka dates. The date casts serialise as UTC timestamps and the
            // list sliced them, so a price starting on the 1st read as starting
            // on the 31st - on the one screen that says when a price applies.
            ->through(fn (ProductPrice $price) => array_merge($price->toArray(), [
                'effective_from' => $price->effective_from?->toDateString(),
                'effective_to' => $price->effective_to?->toDateString(),
            ]));

        // ⭐ Phase 2 / 1B (17 Oct 2026): today's price of every market product,
        // the market products that may be repriced by hand (those with a
        // received cost), and the last confirmations, for the section above
        // the price list and the "Set today's price" popup.
        $marketPrices = MarketPrice::query()
            ->with(['product:id,name,sku,unit_of_measure_id', 'product.unitOfMeasure:id,abbreviation', 'confirmedBy:id,name'])
            ->get()
            ->sortBy(fn (MarketPrice $row) => $row->product?->name)
            ->values()
            ->map(fn (MarketPrice $row) => [
                'product_id' => $row->product_id,
                'product' => $row->product?->name,
                'sku' => $row->product?->sku,
                'unit' => $row->product?->unitOfMeasure?->abbreviation,
                'unit_price' => (string) $row->unit_price,
                'cost_snapshot' => $row->cost_snapshot === null ? null : (string) $row->cost_snapshot,
                'margin_percent' => $row->cost_snapshot === null ? null : ProductPricing::marginPercent((string) $row->cost_snapshot, (string) $row->unit_price),
                'since' => $row->sinceLabel(),
                'since_date' => $row->since_at->toDateString(),
                'confirmed_by' => $row->confirmedBy?->name,
            ]);

        $marketHistory = ProductPriceHistory::query()
            ->with(['product:id,name', 'confirmedBy:id,name'])
            ->orderByDesc('confirmed_at')->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (ProductPriceHistory $row) => [
                'id' => $row->id,
                'product' => $row->product?->name,
                'source' => $row->source,
                'cost_snapshot' => $row->cost_snapshot === null ? null : (string) $row->cost_snapshot,
                'prior_price' => $row->prior_price === null ? null : (string) $row->prior_price,
                'suggested_price' => $row->suggested_price === null ? null : (string) $row->suggested_price,
                'new_price' => (string) $row->new_price,
                'is_override' => (bool) $row->is_override,
                'override_reason' => $row->override_reason,
                'confirmed_by' => $row->confirmedBy?->name,
                'confirmed_at' => $row->confirmed_at->format('Y-m-d H:i'),
            ]);

        return Inertia::render('sales/prices/index', [
            'prices' => $prices,
            'filters' => $filters,
            'marketPrices' => $marketPrices,
            'marketHistory' => $marketHistory,
            'marketProducts' => Product::query()->where('is_active', true)
                ->where('pricing_mode', PricingMode::Market->value)
                ->with('unitOfMeasure:id,abbreviation')
                ->orderBy('name')->get(['id', 'name', 'sku', 'unit_of_measure_id'])
                ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'unit' => $product->unitOfMeasure?->abbreviation]),
            'products' => Product::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'sku']),
            'customers' => Customer::query()->listable()->active()->orderBy('name')->get(['id', 'name']),
            // ⚠ D-B (18 Oct 2026): not OWNER, which is priced at the batch cost.
            'priceGroups' => PriceGroup::query()->active()->where('price_basis', '<>', PriceGroup::BASIS_COST)
                ->orderBy('id')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠ All three FKs tenant-scoped. A bare `exists:` accepts another
            // business's id, and while the resolver's global scope would keep
            // the row from ever pricing anything, a rule that silently never
            // applies is this module's defining failure shape.
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('business_id', $businessId),
            ],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('business_id', $businessId),
            ],
            // ⚠⚠ A group or a customer, never neither (21 Sep 2026). A price
            // for nobody in particular was the silent fallback that could charge
            // a dealer a retail figure; now it would reach consumers only, so it
            // is asked for by name: choose Retail.
            'price_group_id' => [
                'nullable', 'integer', 'required_without:customer_id',
                Rule::exists('price_groups', 'id')->where('business_id', $businessId),
            ],
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'price_group_id.required_without' => 'Choose who this price is for: a price group (Retail for consumers) or one customer.',
        ]);

        // ⚠ A rule cannot be for a customer AND a group at once - the resolver
        // has three ordered tiers, and a row claiming two of them has no defined
        // place in that order.
        if (! empty($data['customer_id']) && ! empty($data['price_group_id'])) {
            throw ValidationException::withMessages([
                'price_group_id' => 'A price is either for one customer or for a group, not both.',
            ]);
        }

        ProductPrice::create([
            'product_id' => $data['product_id'],
            'unit_price' => $data['unit_price'],
            'effective_from' => Carbon::parse($data['effective_from'])->startOfDay(),
            'effective_to' => isset($data['effective_to'])
                ? Carbon::parse($data['effective_to'])->startOfDay()
                : null,
            'customer_id' => $data['customer_id'] ?? null,
            'price_group_id' => $data['price_group_id'] ?? null,
            'min_quantity' => $data['min_quantity'] ?? 0,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Price recorded.');
    }

    /**
     * Ends a price from a date, rather than deleting it.
     */
    public function close(Request $request, ProductPrice $productPrice): RedirectResponse
    {
        $data = $request->validate([
            'effective_to' => ['required', 'date'],
        ]);

        $endsOn = Carbon::parse($data['effective_to'])->startOfDay();

        if ($endsOn->lt($productPrice->effective_from)) {
            throw ValidationException::withMessages([
                'effective_to' => 'A price cannot end before it started.',
            ]);
        }

        $productPrice->update(['effective_to' => $endsOn]);

        return back()->with('success', 'Price closed.');
    }
}
