<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buy X Get Y free (Module 3, Slice 5). Setting one is a pricing decision,
 * so it lives behind the pricing permission; the free line itself is decided
 * server-side at the counter (ApplyPromotions), never claimed by a screen.
 */
class PromotionController extends Controller
{
    public function index(): Response
    {
        $products = Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']);
        $names = $products->keyBy('id');

        return Inertia::render('sales/promotions', [
            'promotions' => DB::table('promotions')
                ->where('business_id', app(BusinessContext::class)->id())
                ->orderByDesc('id')
                ->get()
                ->map(fn ($promotion) => [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'buy_product' => $names[$promotion->buy_product_id]->name ?? '?',
                    'buy_quantity' => $promotion->buy_quantity,
                    'get_product' => $names[$promotion->get_product_id]->name ?? '?',
                    'get_quantity' => $promotion->get_quantity,
                    'starts_on' => $promotion->starts_on,
                    'ends_on' => $promotion->ends_on,
                    'status' => $promotion->status,
                ]),
            'products' => $products,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // ⚠ SAL-006 (audit 14 Oct 2026): every id is checked inside this business.
            // A bare exists: let another business's id through to a scoped lookup,
            // which gave a blank 500 or stored a foreign id, instead of a sentence.
            'buy_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'buy_quantity' => ['required', 'numeric', 'gt:0'],
            'get_product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'get_quantity' => ['required', 'numeric', 'gt:0'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);

        DB::table('promotions')->insert($data + [
            'business_id' => app(BusinessContext::class)->id(),
            'status' => 'active',
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Promotion is live for its dates.');
    }

    /** Pause or resume - a deal is never deleted, its history happened. */
    public function toggle(Request $request, int $promotion): RedirectResponse
    {
        $row = DB::table('promotions')
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('id', $promotion)->first();
        abort_if($row === null, 404);

        DB::table('promotions')->where('id', $promotion)->update([
            'status' => $row->status === 'active' ? 'paused' : 'active',
            'updated_at' => now(),
        ]);

        return back()->with('success', $row->status === 'active' ? 'Promotion paused.' : 'Promotion resumed.');
    }
}
