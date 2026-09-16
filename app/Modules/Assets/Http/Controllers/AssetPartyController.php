<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Enums\AssetPartyKind;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetParty;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outside parties that can hold a thing (slice A2): repair shops, freelancers.
 * Switched off, never deleted: movements point at them.
 */
class AssetPartyController extends Controller
{
    public function index(Request $request): Response
    {
        $holding = AssetItem::query()->whereNotNull('custodian_party_id')
            ->selectRaw('custodian_party_id, count(*) as n')->groupBy('custodian_party_id')->pluck('n', 'custodian_party_id');

        return Inertia::render('assets/parties/index', [
            'parties' => AssetParty::query()->with('supplier:id,name')->orderBy('name')->get()
                ->map(fn (AssetParty $party) => [
                    'id' => $party->id,
                    'name' => $party->name,
                    'organisation' => $party->organisation,
                    'phone' => $party->phone,
                    'kind' => $party->kind->value,
                    'kind_label' => $party->kind->label(),
                    'supplier_id' => $party->supplier_id,
                    'supplier' => $party->supplier?->name,
                    'note' => $party->note,
                    'is_active' => $party->is_active,
                    'holding' => (int) ($holding[$party->id] ?? 0),
                ]),
            'kinds' => AssetPartyKind::options(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'can_manage' => $request->user()->can('assets.items.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $party = AssetParty::create($this->validated($request));

        return back()->with('success', "{$party->name} added.");
    }

    public function update(Request $request, AssetParty $party): RedirectResponse
    {
        $party->update($this->validated($request, $party));

        return back()->with('success', "{$party->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AssetParty $party = null): array
    {
        $businessId = app(BusinessContext::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('asset_parties', 'name')->where('business_id', $businessId)->ignore($party?->id)],
            'organisation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'kind' => ['required', Rule::enum(AssetPartyKind::class)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
