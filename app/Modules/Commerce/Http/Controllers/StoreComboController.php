<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\CartComboLine;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Commerce\Support\ComboPricing;
use App\Modules\Foundation\Support\DocumentMoney;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bundles a shop sells. Website Phase 2, slice 2.5a.
 *
 * ⚠⚠ TWO PERMISSIONS, BECAUSE THIS SCREEN HOLDS TWO KINDS OF DECISION. Putting
 * jars together and describing them is editorial work, `commerce.content.manage`,
 * the pair the catalogue screen uses. A bundle's PRICE is money a customer is
 * charged, and it is gated by `sales.prices.manage` - the permission that
 * already guards the selling price list. The catalogue screen refuses a price
 * field outright because a price typed on a content screen is a second opinion
 * about money; D25 allows a bundle price, so here the field exists, and the
 * same people who may set a price on the price list are the only ones who may
 * set this one.
 *
 * ⚠ Every refusal is recomputed on every view, through the class the shop and
 * the checkout ask. A price list change can push a bundle above its parts after
 * it was published, and a screen that only checked at publish time would keep
 * saying it was fine.
 */
class StoreComboController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $this->authorize('commerce.stores.view');

        $combos = $store->combos()
            ->with('items.product:id,name,sku,is_active')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (StoreCombo $combo) use ($store) {
                $quote = ComboPricing::quote($store, $combo);

                return [
                    'id' => $combo->id,
                    'title' => $combo->title,
                    'slug' => $combo->slug,
                    'summary' => $combo->summary,
                    'image_media_id' => $combo->image_media_id,
                    'price' => $combo->price === null ? null : (string) $combo->price,
                    'position' => $combo->position,
                    'published_at' => $combo->published_at?->format('d M Y'),
                    'items' => $combo->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'name' => $item->product?->name,
                        'sku' => $item->product?->sku,
                        'quantity' => $item->quantity,
                    ])->values(),
                    'parts_total' => $quote->sellable() ? DocumentMoney::amount($quote->partsTotal->toTaka()) : null,
                    'total' => $quote->sellable() ? DocumentMoney::amount($quote->total->toTaka()) : null,
                    'saving' => $quote->sellable() ? DocumentMoney::amount($quote->saving()->toTaka()) : null,
                    'refusal' => $quote->refusal,
                ];
            })
            ->values();

        return Inertia::render('commerce/stores/combos', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'price_group' => CataloguePublishing::priceGroupLabel($store),
            ],
            'combos' => $combos,
            // ⚠ Stocked products only. A service cannot be delivered, so a
            // sales order refuses it - offering one here would build a bundle
            // the checkout can never sell.
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'type'])
                ->filter(fn (Product $product) => $product->type->isStocked())
                ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku])
                ->values(),
            'media' => $store->media()->orderByDesc('id')->get(['id', 'uuid', 'alt_text', 'is_decorative']),
            'can_manage' => $request->user()->can('commerce.content.manage'),
            'can_set_price' => $request->user()->can('sales.prices.manage'),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        $data = $this->validated($request, $store);

        $this->guardPrice($request, null, $data['price'] ?? null);

        DB::transaction(function () use ($store, $data) {
            $combo = $store->combos()->create([
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'slug' => $this->slugFor($store, $data['title']),
                'price' => $data['price'] ?? null,
                'position' => (int) $store->combos()->max('position') + 1,
            ]);

            $this->syncItems($combo, $data['items']);
        });

        // ⚠ Created as a DRAFT. A bundle is composed and priced before anybody
        // can buy it, and publishing is a separate act that asks whether it can
        // actually be sold.
        return back()->with('success', $data['title'].' is saved as a draft. Publish it when it is ready.');
    }

    public function update(Request $request, Store $store, StoreCombo $combo): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($combo->store_id === $store->id, 404);

        $data = $this->validated($request, $store, $combo);

        $this->guardPrice($request, $combo, $data['price'] ?? null);

        DB::transaction(function () use ($combo, $data) {
            $combo->update([
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'slug' => $data['slug'],
                'image_media_id' => $data['image_media_id'] ?? null,
                'price' => $data['price'] ?? null,
                'position' => $data['position'],
            ]);

            $this->syncItems($combo, $data['items']);
        });

        return back()->with('success', 'Saved.');
    }

    public function publish(Store $store, StoreCombo $combo): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($combo->store_id === $store->id, 404);

        if ($refusal = ComboPricing::quote($store, $combo)->refusal) {
            throw ValidationException::withMessages(['published_at' => $refusal]);
        }

        $combo->publish();

        return back()->with('success', $combo->title.' is now on sale.');
    }

    /**
     * ⚠ Keeps the row, the address and the words, the rule a product follows,
     * so a seasonal gift box can come back next year at the same URL.
     */
    public function unpublish(Store $store, StoreCombo $combo): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($combo->store_id === $store->id, 404);

        $combo->unpublish();

        return back()->with('success', 'Taken off sale. The address and its wording are kept.');
    }

    /**
     * ⛔ Refused once a basket has held it. A bundle in somebody's basket must
     * not vanish from it silently, and one that became an order is history; the
     * table's restrictOnDelete says the same, and this says it as a sentence.
     */
    public function destroy(Store $store, StoreCombo $combo): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($combo->store_id === $store->id, 404);

        $baskets = CartComboLine::query()->where('store_combo_id', $combo->id)->count();

        if ($baskets > 0) {
            throw ValidationException::withMessages([
                'combo' => sprintf(
                    '%s has been put in %d %s, so it cannot be deleted. Take it off sale instead.',
                    $combo->title, $baskets, $baskets === 1 ? 'basket' : 'baskets',
                ),
            ]);
        }

        $combo->delete();

        return back()->with('success', 'Bundle deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Store $store, ?StoreCombo $combo = null): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:500'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];

        if ($combo !== null) {
            $rules += [
                'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    Rule::unique('store_combos', 'slug')->where('store_id', $store->id)->ignore($combo->id)],
                'image_media_id' => ['nullable', 'integer',
                    Rule::exists('store_media', 'id')->where('store_id', $store->id)],
                'position' => ['required', 'integer', 'min:0'],
            ];
        }

        $data = $request->validate($rules, [
            'items.required' => 'A bundle needs at least one product.',
            'items.*.product_id.distinct' => 'The same product is listed twice. Raise its quantity instead.',
            'slug.regex' => 'A web address is lowercase letters, digits and single hyphens, e.g. pickle-duo.',
        ]);

        // ⚠ Two jars of one thing is a bundle; one jar of one thing is just the
        // product with a second page competing for its search result.
        if (array_sum(array_column($data['items'], 'quantity')) < 2) {
            throw ValidationException::withMessages(['items' => 'A bundle needs at least two items in it.']);
        }

        // ⛔ Looked up through the tenant scope, so another business's product
        // id reads as "not found" rather than joining this shop's bundle.
        $products = Product::query()->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');

        foreach ($data['items'] as $index => $item) {
            $product = $products->get($item['product_id']);

            if ($product === null) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'That product is not in this business.']);
            }

            if (! $product->type->isStocked()) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => "{$product->name} is a service and cannot be sold in a bundle."]);
            }
        }

        return $data;
    }

    /**
     * ⛔ Only a holder of `sales.prices.manage` may set, change or clear a
     * bundle's price. Everybody else may still compose the bundle, and the
     * price they cannot touch is left exactly as it was.
     */
    private function guardPrice(Request $request, ?StoreCombo $combo, mixed $price): void
    {
        if ($request->user()->can('sales.prices.manage')) {
            return;
        }

        $current = $combo?->price === null ? null : (string) $combo->price;
        $proposed = ($price === null || $price === '') ? null : number_format((float) $price, 2, '.', '');

        if ($current !== $proposed) {
            throw ValidationException::withMessages([
                'price' => 'Setting a bundle price needs permission to manage selling prices.',
            ]);
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     */
    private function syncItems(StoreCombo $combo, array $items): void
    {
        $combo->items()->delete();

        foreach ($items as $item) {
            $combo->items()->create([
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ]);
        }
    }

    /**
     * ⚠ Suffixed rather than refused on a collision, the rule the catalogue's
     * slugs follow.
     */
    private function slugFor(Store $store, string $title): string
    {
        $base = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($title))) ?? '', '-');
        $base = $base === '' ? 'bundle' : $base;

        $slug = $base;
        $suffix = 2;

        while (StoreCombo::query()->where('store_id', $store->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
