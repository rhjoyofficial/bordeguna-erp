<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales → Discounts. Sell something for less than its list price, for a while.
 *
 * ⚠⚠ A DISCOUNT IS A PRICE ROW, NOT A NEW CONCEPT. `ResolveSellingPrice` is
 * untouched by this whole feature and stays the only thing that decides a
 * price. What this screen adds is a way to write the right row without knowing
 * the ranking rules, plus one guard the raw price screen cannot give you.
 *
 * ⚠⚠ THE GUARD: after writing, this asks the resolver what a customer would
 * ACTUALLY be charged and reports it back. Ranking is specificity, then
 * quantity, then start date, then id - so a discount starting before a newer
 * list price silently loses to it, and the operator would never know from the
 * form alone. Rather than reason about that in the abstract, the screen asks
 * the engine and says the answer out loud. Joy's standing rule: preview before
 * any financially meaningful write.
 *
 * ⚠ Bulk selling is what this is for (Joy, 2026-08-23): 125 g is the pack, and
 * 500 g means four of them. Set a discount from four packs up rather than
 * inventing a 500 g product or a tier price list.
 */
class DiscountController extends Controller
{
    public function index(Request $request, ResolveSellingPrice $resolver): Response
    {
        $this->authorize('sales.prices.manage');

        $on = $request->filled('on')
            ? Carbon::parse($request->string('on')->toString())
            : Carbon::today();

        $discounts = ProductPrice::query()
            ->where('is_discount', true)
            ->with(['product', 'priceGroup'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProductPrice $p) => [
                'id' => $p->id,
                'product' => $p->product?->name,
                'sku' => $p->product?->sku,
                'label' => $p->discount_label,
                'group' => $p->priceGroup?->name ?? 'All customers',
                'min_quantity' => (float) $p->min_quantity,
                'unit_price' => (float) $p->unit_price,
                'effective_from' => $p->effective_from?->toDateString(),
                'effective_to' => $p->effective_to?->toDateString(),
                // ⚠ Whether this row is the one that actually wins today, asked
                // of the resolver rather than worked out here.
                'is_winning' => $this->winsOn($resolver, $p, $on),
            ])
            ->values();

        return Inertia::render('sales/discounts', [
            'on' => $on->toDateString(),
            'discounts' => $discounts,
            'products' => Product::query()
                ->where('is_active', true)
                // The same definition of "sellable" the preflight check uses,
                // so the two never disagree about what needs a price.
                ->where('type', ProductType::FinishedGood)
                ->orderBy('name')
                ->get(['id', 'sku', 'name'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    // The list price a discount is measured against.
                    'list_price' => $this->listPriceFor($p, $on),
                ])
                ->values(),
            'groups' => PriceGroup::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request, ResolveSellingPrice $resolver): RedirectResponse
    {
        $this->authorize('sales.prices.manage');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'price_group_id' => ['nullable', 'integer', 'exists:price_groups,id'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'min_quantity' => ['nullable', 'numeric', 'min:1'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'discount_label' => ['nullable', 'string', 'max:120'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $from = Carbon::parse($data['effective_from']);

        $listPrice = $this->listPriceFor($product, $from);

        // ⚠ A "discount" that is not lower than the price it replaces is a
        // price rise wearing the wrong label, and would be read on the sales
        // floor as a saving. Refused here rather than explained later.
        if ($listPrice !== null && (float) $data['unit_price'] >= $listPrice) {
            return back()->withErrors([
                'unit_price' => sprintf(
                    'That is not a discount: the list price on %s is %s. Enter something lower.',
                    $from->toDateString(),
                    number_format($listPrice, 2),
                ),
            ])->withInput();
        }

        $price = ProductPrice::create([
            'product_id' => $data['product_id'],
            'price_group_id' => $data['price_group_id'] ?: null,
            'customer_id' => null,
            'min_quantity' => $data['min_quantity'] ?? 1,
            'unit_price' => $data['unit_price'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'is_discount' => true,
            'discount_label' => ($data['discount_label'] ?? null) ?: null,
            'note' => 'Discount set from the Discounts screen.',
            'created_by' => Auth::id(),
        ]);

        // ⚠⚠ Ask the engine, do not assume. If something else outranks this row
        // the operator finds out now, on the screen that made it, instead of at
        // the counter when a customer is told the wrong number.
        //
        // ⚠ Probing the start date ALONE is not enough, and the test that
        // proves it was failing until this loop existed: ranking reads
        // `effective_from` before `id`, so a list price that begins LATER, part
        // way through the discount, silently takes over from that day on. Every
        // date on which some other rule for this product begins inside the
        // window is therefore probed too.
        $loses = $this->firstDateItLoses($resolver, $product, $price);

        if ($loses !== null) {
            return back()->with('warning', sprintf(
                'Discount saved, but from %s a customer would be charged %s instead, because another price rule starting that day outranks it. End that rule, or start this discount after it.',
                $loses['on']->toDateString(),
                number_format($loses['price'], 2),
            ));
        }

        return back()->with('success', sprintf(
            '%s discounted to %s from %s.',
            $product->name,
            number_format((float) $data['unit_price'], 2),
            $from->toDateString(),
        ));
    }

    /**
     * End a discount. Closed the day before, never deleted, so what a past
     * invoice was priced on can still be explained.
     */
    public function close(ProductPrice $discount): RedirectResponse
    {
        $this->authorize('sales.prices.manage');

        abort_unless($discount->is_discount, 404);

        $yesterday = Carbon::today()->subDay();

        // A discount that never started can simply be closed before it starts;
        // one already running ends yesterday.
        $endsOn = $discount->effective_from->gt($yesterday)
            ? $discount->effective_from->copy()->subDay()
            : $yesterday;

        $discount->update(['effective_to' => $endsOn]);

        return back()->with('success', 'Discount ended. The list price applies again.');
    }

    /**
     * The best price in force for this product, ignoring who is buying.
     *
     * ⚠ Read through ProductPrice rather than the resolver, because the
     * resolver needs a customer to answer a group-scoped question and this is
     * "what does the price list say", not "what would Karim pay".
     */
    private function listPriceFor(Product $product, Carbon $on): ?float
    {
        $row = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('is_discount', false)
            ->whereNull('customer_id')
            ->inForceOn($on)
            ->orderBy('min_quantity')
            ->first();

        return $row === null ? null : (float) $row->unit_price;
    }

    /**
     * The first day inside this discount's life on which it would NOT be the
     * price charged, and what would be charged instead.
     *
     * ⚠ Probe dates are the discount's own start plus the start of every other
     * rule for the same product that falls inside its window. Those are the
     * only days the winner can change, because a rule's rank moves only when a
     * rule begins or ends.
     *
     * @return array{on: Carbon, price: float}|null
     */
    private function firstDateItLoses(ResolveSellingPrice $resolver, Product $product, ProductPrice $rule): ?array
    {
        $windowEnd = $rule->effective_to;

        $probes = collect([$rule->effective_from]);

        $others = ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('id', '!=', $rule->id)
            ->whereDate('effective_from', '>=', $rule->effective_from->toDateString())
            ->when($windowEnd !== null, fn ($q) => $q->whereDate('effective_from', '<=', $windowEnd->toDateString()))
            ->pluck('effective_from');

        foreach ($others as $date) {
            $probes->push(Carbon::parse($date));
        }

        foreach ($probes->unique(fn (Carbon $d) => $d->toDateString())->sort() as $on) {
            $effective = $this->resolvedPrice($resolver, $product, $rule, $on);

            if ($effective !== null && abs($effective - (float) $rule->unit_price) > 0.0001) {
                return ['on' => $on, 'price' => $effective];
            }
        }

        return null;
    }

    /**
     * What a customer this rule is aimed at would actually be charged.
     *
     * ⚠⚠ THE STAND-IN CUSTOMER IS THE WHOLE POINT. `ResolveSellingPrice`
     * refuses a group-scoped rule when there is nobody to have a group, which
     * is right at a counter with no customer chosen and useless here: asking
     * with a null customer made the guard blind to exactly the rules it was
     * written to check, and it silently reported "all fine" for a discount
     * that had already lost. An unsaved Customer carrying the target group is
     * enough for the real resolver to answer the real question, and nothing is
     * written.
     */
    private function resolvedPrice(ResolveSellingPrice $resolver, Product $product, ProductPrice $rule, Carbon $on): ?float
    {
        $standIn = $rule->price_group_id === null
            ? null
            : new Customer([
                'business_id' => $rule->business_id,
                'price_group_id' => $rule->price_group_id,
            ]);

        $resolved = $resolver->find($product, $standIn, (string) $rule->min_quantity, $on);

        return $resolved === null ? null : (float) $resolved->unitPrice;
    }

    private function winsOn(ResolveSellingPrice $resolver, ProductPrice $rule, Carbon $on): bool
    {
        if ($rule->product === null) {
            return false;
        }

        if ($rule->effective_from->gt($on) || ($rule->effective_to !== null && $rule->effective_to->lt($on))) {
            return false;
        }

        $effective = $this->resolvedPrice($resolver, $rule->product, $rule, $on);

        return $effective !== null && abs($effective - (float) $rule->unit_price) < 0.0001;
    }
}
