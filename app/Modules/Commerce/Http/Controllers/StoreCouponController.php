<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCoupon;
use App\Modules\Commerce\Support\CouponPricing;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The coupons a shop offers. Website Phase 2, slice 2.5b.
 *
 * ⚠⚠ EVERY WRITE NEEDS `sales.prices.manage`, the permission that guards the
 * selling price list. A coupon is a price decision - "this customer pays 40%
 * less" - and a content editor who may describe a jar must not be able to give
 * half of it away. Reading is `commerce.stores.view`, the pair the other shop
 * screens use.
 *
 * ⚠ Usage is shown COUNTED FROM ORDERS on every view, cancelled and rejected ones
 * excluded (Joy, 13 September). There is no counter to drift.
 */
class StoreCouponController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $this->authorize('commerce.stores.view');

        $coupons = $store->coupons()
            ->with('products:id,name', 'combos:id,title', 'giftProduct:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StoreCoupon $coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => (string) $coupon->value,
                'min_purchase' => $coupon->min_purchase === null ? null : (string) $coupon->min_purchase,
                'usage_limit' => $coupon->usage_limit,
                'limit_per_customer' => $coupon->limit_per_customer,
                'starts_at' => $coupon->starts_at?->format('Y-m-d\TH:i'),
                'ends_at' => $coupon->ends_at?->format('Y-m-d\TH:i'),
                'is_active' => $coupon->is_active,
                'is_public' => $coupon->is_public,
                'applies_to' => $coupon->applies_to,
                'product_ids' => $coupon->products->pluck('id')->values(),
                'combo_ids' => $coupon->combos->pluck('id')->values(),
                'is_free_delivery' => $coupon->is_free_delivery,
                'gift_product_id' => $coupon->gift_product_id,
                'gift_quantity' => $coupon->gift_quantity,
                'times_used' => CouponPricing::timesUsed($coupon),
                'advertisable' => CouponPricing::advertisable($store, $coupon->code) !== null,
            ])
            ->values();

        return Inertia::render('commerce/stores/coupons', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'coupons' => $coupons,
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'type'])
                ->filter(fn (Product $product) => $product->type->isStocked())
                ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku])
                ->values(),
            'combos' => $store->combos()->orderBy('title')->get(['id', 'title']),
            'can_manage' => $request->user()->can('sales.prices.manage'),
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('sales.prices.manage');

        $data = $this->validated($request, $store);

        DB::transaction(fn () => $this->save($store->coupons()->make(), $data));

        return back()->with('success', 'Coupon '.$data['code'].' saved.');
    }

    public function update(Request $request, Store $store, StoreCoupon $coupon): RedirectResponse
    {
        $this->authorize('sales.prices.manage');

        abort_unless($coupon->store_id === $store->id, 404);

        $data = $this->validated($request, $store, $coupon);

        DB::transaction(fn () => $this->save($coupon, $data));

        return back()->with('success', 'Saved.');
    }

    /**
     * Many single-use codes at once - an influencer's followers, a printed insert.
     *
     * ⚠ Each code is RANDOM with no 0/O or 1/I, the rule a shop reference follows,
     * because these are read aloud and typed from paper. All of them share one
     * set of terms and none is scoped: a batch is for handing out, and a scope
     * nobody can see on a slip of paper is a promise the slip does not state.
     */
    public function bulk(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('sales.prices.manage');

        $data = $request->validate([
            'prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]*$/'],
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'type' => ['required', Rule::in([StoreCoupon::TYPE_PERCENT, StoreCoupon::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'gt:0', $request->input('type') === StoreCoupon::TYPE_PERCENT ? 'max:100' : 'max:9999999'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $prefix = StoreCoupon::normaliseCode($data['prefix'] ?? '');
        $made = 0;

        DB::transaction(function () use ($store, $data, $prefix, &$made) {
            $attempts = 0;

            while ($made < (int) $data['count'] && $attempts < (int) $data['count'] * 10) {
                $attempts++;
                $code = $prefix.$this->randomTail(8);

                if ($store->coupons()->where('code', $code)->exists()) {
                    continue;
                }

                $store->coupons()->create([
                    'code' => $code,
                    'type' => $data['type'],
                    'value' => $data['value'],
                    'min_purchase' => $data['min_purchase'] ?? null,
                    'usage_limit' => 1,
                    'limit_per_customer' => 1,
                    'ends_at' => $data['ends_at'] ?? null,
                    'is_active' => true,
                    'is_public' => false,
                    'applies_to' => StoreCoupon::APPLIES_ALL,
                ]);

                $made++;
            }
        });

        return back()->with('success', "{$made} single-use codes created.");
    }

    /**
     * ⛔ A code an order has used is not deleted: usage is counted from orders by
     * code, so deleting and re-creating it would hand the new one the old one's
     * history. It is switched off instead.
     */
    public function destroy(Store $store, StoreCoupon $coupon): RedirectResponse
    {
        $this->authorize('sales.prices.manage');

        abort_unless($coupon->store_id === $store->id, 404);

        if (CouponPricing::timesUsed($coupon) > 0) {
            throw ValidationException::withMessages([
                'coupon' => "{$coupon->code} has been used on an order, so it cannot be deleted. Switch it off instead.",
            ]);
        }

        $coupon->delete();

        return back()->with('success', 'Coupon deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Store $store, ?StoreCoupon $coupon = null): array
    {
        $request->merge(['code' => StoreCoupon::normaliseCode($request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/',
                Rule::unique('store_coupons', 'code')->where('store_id', $store->id)->ignore($coupon?->id)],
            'type' => ['required', Rule::in([StoreCoupon::TYPE_PERCENT, StoreCoupon::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'min:0', $request->input('type') === StoreCoupon::TYPE_PERCENT ? 'max:100' : 'max:9999999'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'applies_to' => ['required', Rule::in([StoreCoupon::APPLIES_ALL, StoreCoupon::APPLIES_PRODUCTS, StoreCoupon::APPLIES_COMBOS])],
            'product_ids' => ['array', 'required_if:applies_to,products'],
            'product_ids.*' => ['integer'],
            'combo_ids' => ['array', 'required_if:applies_to,combos'],
            'combo_ids.*' => ['integer', Rule::exists('store_combos', 'id')->where('store_id', $store->id)],
            'is_free_delivery' => ['boolean'],
            'gift_product_id' => ['nullable', 'integer'],
            'gift_quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
        ], [
            'code.regex' => 'A code is letters, digits and hyphens.',
            'product_ids.required_if' => 'Choose the products this code applies to.',
            'combo_ids.required_if' => 'Choose the bundles this code applies to.',
        ]);

        // ⚠ A coupon that takes nothing off and gives nothing is a code that
        // does nothing, which a customer reads as the shop not keeping its word.
        if ((float) $data['value'] === 0.0 && empty($data['is_free_delivery']) && empty($data['gift_product_id'])) {
            throw ValidationException::withMessages(['value' => 'A code must take something off, give free delivery or give a gift.']);
        }

        // ⛔ Through the tenant scope, so another business's product reads as
        // not found; and never a service, which cannot be delivered.
        $ids = array_filter([...($data['product_ids'] ?? []), $data['gift_product_id'] ?? null]);
        $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $product = $products->get($id);

            if ($product === null || ! $product->type->isStocked()) {
                throw ValidationException::withMessages([
                    ((int) $id === (int) ($data['gift_product_id'] ?? 0) ? 'gift_product_id' : 'product_ids') => 'That product cannot be used here.',
                ]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(StoreCoupon $coupon, array $data): void
    {
        $coupon->fill([
            'code' => $data['code'],
            'type' => $data['type'],
            'value' => $data['value'],
            'min_purchase' => $data['min_purchase'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'limit_per_customer' => $data['limit_per_customer'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'applies_to' => $data['applies_to'],
            'is_free_delivery' => (bool) ($data['is_free_delivery'] ?? false),
            'gift_product_id' => $data['gift_product_id'] ?? null,
            'gift_quantity' => $data['gift_quantity'] ?? 1,
        ])->save();

        // ⚠ The scope list that is not in force is emptied, so a coupon switched
        // from "these products" to "everything" does not keep a hidden list that
        // comes back if somebody switches it again.
        $coupon->products()->sync($data['applies_to'] === StoreCoupon::APPLIES_PRODUCTS ? ($data['product_ids'] ?? []) : []);
        $coupon->combos()->sync($data['applies_to'] === StoreCoupon::APPLIES_COMBOS ? ($data['combo_ids'] ?? []) : []);
    }

    private function randomTail(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tail = '';

        for ($i = 0; $i < $length; $i++) {
            $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $tail;
    }
}
