<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Which products a shop sells, and what it calls them.
 *
 * ⛔⛔ PUBLISHING DOES NOT FORK THE PRODUCT MASTER. Everything here is about
 * appearance and address: does this product appear on this shop, at what URL,
 * under what title. The name, unit, SKU and category have one owner, and what a
 * visitor pays comes from the ordinary price list read at the store's own price
 * group. There is no price field on this screen for that reason.
 *
 * ⚠ Read is `commerce.stores.view`, writes are `commerce.content.manage` - the
 * same pair the pages screen uses, because publishing a product to a shop is
 * editorial work, not the Owner-only act of taking a whole store live.
 */
class StoreCatalogueController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $this->authorize('commerce.stores.view');

        $search = $request->string('search')->toString();

        $published = $store->products()
            ->with(['product:id,name,sku,is_active', 'image:id,uuid,alt_text'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (StoreProduct $row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->product?->sku,
                'master_name' => $row->product?->name,
                'title' => $row->displayTitle(),
                'title_override' => $row->title_override,
                'summary_override' => $row->summary_override,
                'slug' => $row->slug,
                'position' => $row->position,
                'image_media_id' => $row->image_media_id,
                'published_at' => $row->published_at?->format('d M Y'),
                // ⚠ Recomputed on every view, not stored. A price can expire
                // after a product was published, and a catalogue that only
                // checked at publish time would keep saying it was fine.
                'refusal' => $row->product === null
                    ? 'The product this row points at no longer exists.'
                    : CataloguePublishing::refusalFor($store, $row->product),
            ])
            ->values();

        $alreadyPublished = $store->products()->pluck('product_id');

        return Inertia::render('commerce/stores/catalogue', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'key' => $store->key,
                'status' => $store->status->value,
                'price_group' => CataloguePublishing::priceGroupLabel($store),
            ],
            'published' => $published,
            'candidates' => Product::query()
                ->where('is_active', true)
                ->whereNotIn('id', $alreadyPublished)
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
                ))
                ->orderBy('name')
                ->limit(30)
                ->get(['id', 'name', 'sku'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    // ⭐ Shown BEFORE anybody publishes, so the reason a product
                    // cannot go on sale is read on the screen where it would be
                    // fixed rather than discovered as a validation error.
                    'refusal' => CataloguePublishing::refusalFor($store, $product),
                ])
                ->values(),
            'filters' => ['search' => $search],
            'media' => $store->media()->orderByDesc('id')->get(['id', 'uuid', 'alt_text', 'is_decorative']),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);

        $refusal = CataloguePublishing::refusalFor($store, $product);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['product_id' => $refusal]);
        }

        $store->products()->create([
            'product_id' => $product->id,
            'slug' => CataloguePublishing::slugFor($store, (string) $product->name),
            'position' => (int) $store->products()->max('position') + 1,
            // ⚠ Published immediately. A row that exists but is invisible is a
            // state somebody has to remember to finish, and the point of this
            // screen is that publishing is one decision.
            'published_at' => now(),
        ]);

        return back()->with('success', $product->name.' is now on sale at '.$store->name.'.');
    }

    public function update(Request $request, Store $store, StoreProduct $storeProduct): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($storeProduct->store_id === $store->id, 404);

        $data = $request->validate([
            // ⚠ Unique within THIS store only. Two shops in the group may both
            // want /products/honey, and a global rule would hand the second one
            // a worse address for no reason.
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('store_products', 'slug')->where('store_id', $store->id)->ignore($storeProduct->id)],
            'title_override' => ['nullable', 'string', 'max:255'],
            'summary_override' => ['nullable', 'string', 'max:500'],
            'image_media_id' => ['nullable', 'integer',
                // ⛔ From THIS store's library. A dangling reference, or another
                // shop's photography, is what the media library exists to stop.
                Rule::exists('store_media', 'id')->where('store_id', $store->id)],
            'position' => ['required', 'integer', 'min:0'],
        ], [
            'slug.regex' => 'A web address is lowercase letters, digits and single hyphens, e.g. sundarban-honey.',
        ]);

        $storeProduct->update($data);

        return back()->with('success', 'Saved.');
    }

    /**
     * ⚠ Takes the product off sale and keeps the row, so the address and the
     * words written for it survive. Somebody putting a seasonal product back on
     * sale next year should not have to write it again, and the URL that was
     * indexed should not change.
     */
    public function unpublish(Store $store, StoreProduct $storeProduct): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($storeProduct->store_id === $store->id, 404);

        $storeProduct->update(['published_at' => null]);

        return back()->with('success', 'Taken off sale. The address and its wording are kept.');
    }

    public function publishAgain(Store $store, StoreProduct $storeProduct): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($storeProduct->store_id === $store->id, 404);

        $product = $storeProduct->product;

        $refusal = $product === null
            ? 'The product this row points at no longer exists.'
            : CataloguePublishing::refusalFor($store, $product);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['published_at' => $refusal]);
        }

        $storeProduct->update(['published_at' => now()]);

        return back()->with('success', 'Back on sale.');
    }

    public function destroy(Store $store, StoreProduct $storeProduct): RedirectResponse
    {
        $this->authorize('commerce.content.manage');

        abort_unless($storeProduct->store_id === $store->id, 404);

        $storeProduct->delete();

        return back()->with('success', 'Removed from this shop. The product itself is untouched.');
    }
}
