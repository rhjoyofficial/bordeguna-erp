<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmLookup;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two reporting axes: where leads come from, and which effort produced them.
 *
 * ⚠⚠ THIS SCREEN EXISTS BECAUSE THE TABLES ARE USELESS WITHOUT IT. `lead_sources`
 * and `campaigns` are in slice 1's scope, and a table nothing can write to is the
 * `products.barcode` failure - a column that exists, validates, and can never
 * hold a value. Four separate fields have shipped that way on this project.
 *
 * ⚠ Gated on `crm.settings.manage`, not `crm.leads.manage`. Staff capture leads;
 * inventing a source mid-campaign is how a margin report ends up with four
 * spellings of "Meta" (C-CRM-14).
 */
class CrmSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('crm/settings/index', [
            'sources' => LeadSource::withCount('campaigns')->orderBy('name')
                ->get(['id', 'code', 'name', 'is_active', 'note']),
            'campaigns' => Campaign::with(['leadSource:id,name', 'brand:id,name'])->orderBy('name')
                ->get(['id', 'lead_source_id', 'brand_id', 'code', 'name', 'external_campaign_id', 'starts_on', 'ends_on', 'is_active', 'note'])
                ->map(fn (Campaign $campaign) => [
                    'id' => $campaign->id,
                    'code' => $campaign->code,
                    'name' => $campaign->name,
                    'lead_source_id' => $campaign->lead_source_id,
                    'source' => $campaign->leadSource?->name,
                    'brand_id' => $campaign->brand_id,
                    'brand' => $campaign->brand?->name,
                    'external_campaign_id' => $campaign->external_campaign_id,
                    'starts_on' => $campaign->starts_on?->toDateString(),
                    'ends_on' => $campaign->ends_on?->toDateString(),
                    'is_active' => $campaign->is_active,
                    'note' => $campaign->note,
                ]),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'lists' => $this->lists(),
        ]);
    }

    /**
     * The editable lists a lead picks from (CRM lead slice 2c, plan D15), plus
     * the lost reasons, which had no screen at all until now: `ReasonController`
     * offers inventory contexts only, so `leads.lost_reason_id` could hold
     * nothing anybody had been able to create.
     *
     * @return list<array{key:string, label:string, entries:mixed}>
     */
    private function lists(): array
    {
        $lists = collect(CrmLookupList::cases())->map(fn (CrmLookupList $list) => [
            'key' => $list->value,
            'label' => $list->label(),
            'entries' => CrmLookup::query()->ofList($list)->ordered()->get(['id', 'name', 'is_active']),
        ])->all();

        $lists[] = [
            'key' => self::LOST_REASONS,
            'label' => 'Reasons a lead is lost',
            'entries' => Reason::forContext(self::LOST_REASONS)->orderBy('name')->get(['id', 'name', 'is_active']),
        ];

        return $lists;
    }

    /** The `reasons.context` a lead's lost reason must carry (C-CRM-10). */
    private const LOST_REASONS = 'lead_lost';

    public function storeListEntry(Request $request, string $list): RedirectResponse
    {
        $name = $this->validatedEntryName($request, $list);

        if ($list === self::LOST_REASONS) {
            Reason::create(['context' => self::LOST_REASONS, 'name' => $name, 'is_active' => true]);
        } else {
            CrmLookup::create([
                'list' => $list,
                'name' => $name,
                'sort_order' => (int) CrmLookup::query()->ofList(CrmLookupList::from($list))->max('sort_order') + 1,
            ]);
        }

        return back()->with('success', "Added {$name}.");
    }

    /**
     * Rename an entry, or retire it from the pickers.
     *
     * ⚠ Retired, never deleted: a shop recorded under an entry must still read
     * it after the entry leaves the picker. There is no DELETE route.
     */
    public function updateListEntry(Request $request, string $list, int $entry): RedirectResponse
    {
        $row = $list === self::LOST_REASONS
            ? Reason::forContext(self::LOST_REASONS)->findOrFail($entry)
            : CrmLookup::query()->ofList(CrmLookupList::from($list))->findOrFail($entry);

        $name = $this->validatedEntryName($request, $list, $row->id);
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];

        $row->update(['name' => $name, 'is_active' => $active]);

        return back()->with('success', "Saved {$name}.");
    }

    private function validatedEntryName(Request $request, string $list, ?int $ignore = null): string
    {
        $businessId = app(BusinessContext::class)->id();

        // ⚠ Unique inside its own list and business, so "Wholesale / arot" can be
        // both a shop type and a supplier type without either blocking the other.
        $unique = $list === self::LOST_REASONS
            ? Rule::unique('reasons', 'name')->where('business_id', $businessId)->where('context', self::LOST_REASONS)
            : Rule::unique('crm_lookups', 'name')->where('business_id', $businessId)->where('list', $list);

        return trim($request->validate([
            'name' => ['required', 'string', 'max:120', $unique->ignore($ignore)],
        ])['name']);
    }

    public function storeSource(Request $request): RedirectResponse
    {
        LeadSource::create($this->validatedSource($request));

        return back()->with('success', 'Lead source added.');
    }

    public function updateSource(Request $request, LeadSource $leadSource): RedirectResponse
    {
        $leadSource->update($this->validatedSource($request, $leadSource));

        return back()->with('success', 'Lead source updated.');
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        Campaign::create($this->validatedCampaign($request));

        return back()->with('success', 'Campaign added.');
    }

    public function updateCampaign(Request $request, Campaign $campaign): RedirectResponse
    {
        $campaign->update($this->validatedCampaign($request, $campaign));

        return back()->with('success', 'Campaign updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSource(Request $request, ?LeadSource $source = null): array
    {
        $businessId = $source?->business_id ?? app(BusinessContext::class)->id();

        return $request->validate([
            // ⚠ The frozen identity key, unique per business. Renaming a source
            // must not create a second one, which is why reporting groups by the
            // row and not by the display name.
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('lead_sources', 'code')->where('business_id', $businessId)->ignore($source?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCampaign(Request $request, ?Campaign $campaign = null): array
    {
        $businessId = $campaign?->business_id ?? app(BusinessContext::class)->id();

        return $request->validate([
            // ⚠⚠ Business-scoped, like every other user-supplied foreign key
            // here. A bare `exists:` accepts another tenant's id (C-CRM-1).
            'lead_source_id' => ['required', Rule::exists('lead_sources', 'id')->where('business_id', $businessId)],
            // ⚠ C-CRM-11: nullable. A generic campaign is legitimate.
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('business_id', $businessId)],
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('campaigns', 'code')->where('business_id', $businessId)->ignore($campaign?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            // ⚠ C-CRM-8: Meta's id, preserved as supplied. Ours is `code`.
            'external_campaign_id' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['required', 'boolean'],
            'note' => ['nullable', 'string'],
        ]);
    }
}
