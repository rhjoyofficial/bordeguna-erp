<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Group product links (Module 6, Slice 2). The partner-link design applied
 * to SKUs: no shared master, no renames - the link IS the knowledge. The
 * glance shows stock and today's list price side by side, for the companies
 * the viewer belongs to.
 */
class GroupProductController extends Controller
{
    public function index(Request $request): Response
    {
        $businessIds = $request->user()->businesses()->pluck('businesses.id');
        $businessNames = DB::table('businesses')->whereIn('id', $businessIds)->pluck('name', 'id');

        $links = DB::table('group_product_links')->orderBy('name')->get()
            ->map(function ($link) use ($businessIds, $businessNames) {
                $members = DB::table('group_product_link_members')
                    ->where('link_id', $link->id)
                    ->whereIn('business_id', $businessIds)
                    ->get()
                    ->map(fn ($member) => [
                        'business_id' => $member->business_id,
                        'business' => $businessNames[$member->business_id] ?? '?',
                        'product_id' => $member->product_id,
                    ] + $this->productFacts($member->business_id, $member->product_id))
                    ->values();

                return [
                    'id' => $link->id,
                    'name' => $link->name,
                    'members' => $members,
                    'total_stock' => round($members->sum('stock'), 4),
                ];
            })
            ->filter(fn ($link) => $link['members']->isNotEmpty())
            ->values();

        return Inertia::render('group/products', [
            'links' => $links,
            'suggestions' => $this->suggestions($businessIds, $businessNames),
        ]);
    }

    public function link(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'members' => ['required', 'array', 'min:2'],
            'members.*.business_id' => ['required', 'integer', 'exists:businesses,id'],
            'members.*.product_id' => ['required', 'integer'],
        ]);

        $visible = $request->user()->businesses()->pluck('businesses.id')->flip();
        foreach ($data['members'] as $member) {
            abort_unless(isset($visible[$member['business_id']]), 403);
        }

        DB::transaction(function () use ($data, $request) {
            $linkId = DB::table('group_product_links')->insertGetId([
                'name' => $data['name'],
                'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($data['members'] as $member) {
                DB::table('group_product_link_members')->insert([
                    'link_id' => $linkId,
                    'business_id' => $member['business_id'],
                    'product_id' => $member['product_id'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Linked as one product across the group.');
    }

    public function unlink(int $link): RedirectResponse
    {
        DB::table('group_product_links')->where('id', $link)->delete();

        return back()->with('success', 'Unlinked. Every company product stands as before.');
    }

    /** @return array{name: string, sku: ?string, stock: float, price: ?float} */
    private function productFacts(int $businessId, int $productId): array
    {
        $row = DB::table('products')->where('business_id', $businessId)->where('id', $productId)->first();

        $stock = (float) DB::table('stock_batches')
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->sum('current_quantity');

        // Today's general list price: the newest general row in force.
        $price = DB::table('product_prices')
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->whereNull('price_group_id')
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')
            ->value('unit_price');

        return [
            'name' => $row->name ?? '?',
            'sku' => $row->sku ?? null,
            'stock' => round($stock, 4),
            'price' => $price !== null ? (float) $price : null,
        ];
    }

    private function suggestions($businessIds, $businessNames)
    {
        $linked = DB::table('group_product_link_members')->get()
            ->map(fn ($member) => $member->business_id.':'.$member->product_id)
            ->flip();

        return DB::table('products')
            ->whereIn('business_id', $businessIds)
            ->whereNotNull('sku')
            ->where('is_active', true)
            ->get(['id', 'business_id', 'name', 'sku'])
            ->filter(fn ($product) => ! isset($linked[$product->business_id.':'.$product->id]))
            ->groupBy('sku')
            ->filter(fn ($group) => $group->pluck('business_id')->unique()->count() > 1)
            ->map(fn ($group, $sku) => [
                'sku' => $sku,
                'members' => $group->sortBy('business_id')->map(fn ($product) => [
                    'business_id' => $product->business_id,
                    'business' => $businessNames[$product->business_id] ?? '?',
                    'product_id' => $product->id,
                    'name' => $product->name,
                ])->values(),
            ])
            ->values();
    }
}
