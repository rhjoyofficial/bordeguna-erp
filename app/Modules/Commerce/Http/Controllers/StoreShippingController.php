<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\DistrictShippingZone;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\StandardDeliveryBands;
use App\Support\BdGeo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What delivery costs on a shop, and which district pays which rate. The
 * Shipping and District Pricing parity screens, built 13 September.
 *
 * ⚠⚠ UNTIL THIS SCREEN, ONLY A DEVELOPER COULD SET A DELIVERY RATE. Slice 2.3
 * built the rule (a district is the only input, the band is derived) and left the
 * rates reachable through the model alone.
 *
 * ⚠ Writes need `commerce.stores.manage` - Owner and Manager (Joy, 13 September).
 * A delivery rate decides what a customer is charged, the same class of decision
 * as the store's price group, which that permission already guards.
 *
 * ⛔ The zone tables carry a store but no business, so nothing here is reached
 * except through the route's store, which IS business-scoped. A zone id from
 * another store is a 404, never another shop's rate.
 *
 * ⚠ A rate changed here reprices every OPEN basket on its next read, because a
 * basket never stores a charge. An order already placed keeps the charge it
 * agreed. The screen says so.
 */
class StoreShippingController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $this->authorize('commerce.stores.view');

        $zones = $store->shippingZones()->withCount('districts')->get();
        $mapped = $store->districtShippingZones()->pluck('shipping_zone_id', 'district');

        return Inertia::render('commerce/stores/shipping', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'zones' => $zones->map(fn (ShippingZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'charge' => (string) $zone->charge,
                'is_default' => (bool) $zone->is_default,
                'sort_order' => $zone->sort_order,
                'districts_count' => $zone->districts_count,
            ])->values(),
            // ⚠ Every district in the country, with the zone it is mapped to or
            // null for "pays the catch-all". A district somebody mapped under a
            // name the geography does not know is listed too, so it is visible
            // rather than silently matching nothing.
            'districts' => collect(BdGeo::districts())->map(fn (array $district) => [
                'name' => $district['name'],
                'bn_name' => $district['bn_name'] ?? null,
                'zone_id' => $mapped->get($district['name']),
            ])->values(),
            'unknown_districts' => $mapped->keys()->reject(fn ($name) => BdGeo::hasDistrict($name))->values(),
            'can_manage' => $request->user()->can('commerce.stores.manage'),
        ]);
    }

    public function storeZone(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('commerce.stores.manage');

        $data = $this->validatedZone($request, $store);

        $zone = $store->shippingZones()->create([
            'name' => $data['name'],
            'charge' => $data['charge'],
            'sort_order' => (int) $store->shippingZones()->max('sort_order') + 1,
        ]);

        // ⚠ The first zone a store gets is its catch-all, or the store still
        // cannot quote anything.
        if (! $store->shippingZones()->where('is_default', true)->exists()) {
            $zone->makeDefault();
        }

        return back()->with('success', "{$zone->name} added.");
    }

    public function updateZone(Request $request, Store $store, ShippingZone $zone): RedirectResponse
    {
        $this->authorize('commerce.stores.manage');
        abort_unless($zone->store_id === $store->id, 404);

        $data = $this->validatedZone($request, $store, $zone);

        $zone->update(['name' => $data['name'], 'charge' => $data['charge']]);

        return back()->with('success', 'Saved. Open baskets see the new rate on their next view; placed orders keep theirs.');
    }

    public function makeDefault(Store $store, ShippingZone $zone): RedirectResponse
    {
        $this->authorize('commerce.stores.manage');
        abort_unless($zone->store_id === $store->id, 404);

        $zone->makeDefault();

        return back()->with('success', "{$zone->name} is now the rate for every district not listed.");
    }

    /**
     * ⛔ The catch-all cannot be deleted. Without it every unlisted district
     * stops being able to order, and the shop learns that from customers.
     * Another zone is made the catch-all first.
     */
    public function destroyZone(Store $store, ShippingZone $zone): RedirectResponse
    {
        $this->authorize('commerce.stores.manage');
        abort_unless($zone->store_id === $store->id, 404);

        if ($zone->is_default) {
            throw ValidationException::withMessages([
                'zone' => "{$zone->name} is the rate for every district not listed, so it cannot be deleted. Make another zone the catch-all first.",
            ]);
        }

        $count = $zone->districts()->count();
        $zone->delete();

        return back()->with('success', $count === 0
            ? 'Zone deleted.'
            : "Zone deleted. Its {$count} ".($count === 1 ? 'district now pays' : 'districts now pay').' the catch-all rate.');
    }

    /**
     * Which district pays which zone, saved as a whole.
     *
     * ⚠ A district left empty pays the catch-all, which is what "not listed"
     * means, so it is DELETED rather than stored as a row pointing at nothing.
     * ⚠ Only real district names are accepted, and only this store's zones.
     */
    public function saveDistricts(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('commerce.stores.manage');

        $data = $request->validate([
            'districts' => ['present', 'array'],
            'districts.*' => ['nullable', 'integer', Rule::exists('shipping_zones', 'id')->where('store_id', $store->id)],
        ]);

        $known = [];

        foreach (array_keys($data['districts']) as $name) {
            $resolved = BdGeo::districtNamed((string) $name);

            if ($resolved === null) {
                throw ValidationException::withMessages(['districts' => "We do not recognise the district {$name}."]);
            }

            $known[$resolved] = $data['districts'][$name];
        }

        DB::transaction(function () use ($store, $known) {
            foreach ($known as $district => $zoneId) {
                if ($zoneId === null) {
                    DistrictShippingZone::query()->where('store_id', $store->id)->where('district', $district)->delete();

                    continue;
                }

                DistrictShippingZone::query()->updateOrCreate(
                    ['store_id' => $store->id, 'district' => $district],
                    ['shipping_zone_id' => (int) $zoneId],
                );
            }
        });

        return back()->with('success', 'District pricing saved.');
    }

    /**
     * ⚠ Only for a store with no zones at all. A store somebody has priced is
     * never overwritten by a default.
     */
    public function applyStandard(Store $store): RedirectResponse
    {
        $this->authorize('commerce.stores.manage');

        if (! StandardDeliveryBands::applyTo($store)) {
            throw ValidationException::withMessages([
                'zone' => 'This shop already has delivery rates. The standard bands are only for a shop with none.',
            ]);
        }

        return back()->with('success', 'The standard delivery bands are in place. Change any rate below.');
    }

    /**
     * @return array{name: string, charge: string}
     */
    private function validatedZone(Request $request, Store $store, ?ShippingZone $zone = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('shipping_zones', 'name')->where('store_id', $store->id)->ignore($zone?->id)],
            'charge' => ['required', 'numeric', 'min:0', 'max:99999'],
        ], [
            'name.unique' => 'This shop already has a zone with that name.',
        ]);
    }
}
