<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\MasterRecords;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Price groups: the kinds of buyer a price is set for (Retail, Dealer,
 * Distributor, Corporate, and any the owner adds), and which of them pay MRP
 * when a product has no price of their own (21 Sep 2026).
 *
 * ⚠⚠ Until now there was no screen at all: the groups existed only because a
 * seeder planted them, so a business could not add "Retail shop" without a
 * developer. The code is fixed once made (prices and customers point at the
 * group, and importers match it by code); the name, VAT basis, MRP rule and
 * active switch are the owner's to change.
 */
class PriceGroupController extends Controller
{
    public function index(): Response
    {
        $groups = PriceGroup::query()
            ->withCount(['customers'])
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [PriceGroup::DEFAULT_CODE])
            ->orderBy('name')
            ->get();

        return Inertia::render('sales/price-groups/index', [
            // ⭐ B24 (24 Oct 2026, Q5 = A): each row's Delete / Switch off / Reactivate,
            // greyed with what uses it.
            'recordActions' => MasterRecords::rows('price_group', $groups, request()->user()),
            'deleteReasons' => MasterRecords::reasons(),
            'groups' => $groups
                ->map(fn (PriceGroup $group) => [
                    'id' => $group->id,
                    'code' => $group->code,
                    'name' => $group->name,
                    'prices_include_tax' => $group->prices_include_tax,
                    'falls_back_to_mrp' => $group->falls_back_to_mrp || $group->code === PriceGroup::DEFAULT_CODE,
                    'is_default' => $group->code === PriceGroup::DEFAULT_CODE,
                    'is_active' => $group->is_active,
                    'customers' => $group->customers_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('price_groups', 'code')->where('business_id', app(BusinessContext::class)->id()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'prices_include_tax' => ['required', 'boolean'],
            'falls_back_to_mrp' => ['required', 'boolean'],
        ], [
            'code.regex' => 'Use capital letters, digits and _ only, e.g. RETAIL_SHOP.',
        ]);

        $group = PriceGroup::create($data + ['is_active' => true]);

        return back()->with('success', "Price group {$group->name} added. Set its prices on each product.");
    }

    public function update(Request $request, PriceGroup $priceGroup): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prices_include_tax' => ['required', 'boolean'],
            'falls_back_to_mrp' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        // ⚠ RETAIL is the consumer price and the group every customer without
        // one is priced in: it always pays MRP when nothing is set, and it can
        // never be switched off, or the walk-in counter would have no prices.
        if ($priceGroup->code === PriceGroup::DEFAULT_CODE) {
            $data['falls_back_to_mrp'] = true;
            $data['is_active'] = true;
        }

        $priceGroup->update($data);

        return back()->with('success', "{$priceGroup->name} updated.");
    }
}
