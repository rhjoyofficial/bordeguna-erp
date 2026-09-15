<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Actions\ArchiveLead;
use App\Modules\Crm\Actions\ConvertLead;
use App\Modules\Crm\Actions\LinkDuplicateLead;
use App\Modules\Crm\Actions\SaveLeadProfile;
use App\Modules\Crm\Actions\VerifyLead;
use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Crm\Enums\LeadImportance;
use App\Modules\Crm\Enums\LeadPotential;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\WarehouseOwnership;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\CrmLookup;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Scopes\LeadVisibilityScope;
use App\Modules\Crm\Support\ApplyLeadCadences;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Support\BdGeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = app(BusinessContext::class)->id();

        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'brand_id' => $request->string('brand_id')->toString(),
            'assigned_to_user_id' => $request->string('assigned_to_user_id')->toString(),
            'lead_type' => $request->string('lead_type')->toString(),
            'verification' => $request->string('verification')->toString(),
            'district' => $request->string('district')->toString(),
            // '' = the working list, 'duplicates', 'archived' or 'all'.
            'show' => $request->string('show')->toString(),
        ];

        $leads = Lead::query()
            ->with([
                'brand:id,name,code', 'assignedTo:id,name', 'convertedCustomer:id,name,code', 'duplicateOf:id,name',
                'verifiedBy:id,name', 'createdBy:id,name',
                'retailerProfile.shopType:id,name', 'distributorProfile', 'supplierProfile.supplierType:id,name',
                'competitorBrands:crm_lookups.id,crm_lookups.name', 'coverageAreas:id,lead_id,district,thana',
            ])
            // ⚠ Searching the NORMALISED phone as well as the raw one. A user who
            // types `01711000000` must find a lead captured as `+8801711000000`,
            // or duplicate detection is theatre.
            ->when($filters['search'], function ($query, $search) {
                $normalized = PhoneIdentity::normalize($search);

                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('market', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('secondary_phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->when($normalized, fn ($inner) => $inner
                        ->orWhere('phone_normalized', 'like', "%{$normalized}%")
                        ->orWhere('secondary_phone_normalized', 'like', "%{$normalized}%")));
            })
            // ⭐ The WORKING list by default (15 Sep 2026). An archived lead and a
            // duplicate are both "somebody decided nobody should act on this",
            // and an officer scrolling past them on a phone in a bazaar is an
            // officer who calls the wrong record. Both stay one filter away.
            ->when($filters['show'] === '', fn ($query) => $query->workable())
            ->when($filters['show'] === 'duplicates', fn ($query) => $query->whereNotNull('duplicate_of_lead_id'))
            ->when($filters['show'] === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($filters['lead_type'], fn ($query, $type) => $query->where('lead_type', $type))
            ->when($filters['verification'] === 'verified', fn ($query) => $query->whereNotNull('verified_at'))
            ->when($filters['verification'] === 'unverified', fn ($query) => $query->whereNull('verified_at'))
            ->when($filters['district'], fn ($query, $district) => $query->where('district', $district))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['brand_id'], fn ($query, $brandId) => $brandId === 'none'
                ? $query->whereNull('brand_id')
                : $query->where('brand_id', $brandId))
            ->when($filters['assigned_to_user_id'], fn ($query, $userId) => $userId === 'none'
                ? $query->whereNull('assigned_to_user_id')
                : $query->where('assigned_to_user_id', $userId))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Lead $lead) => [
                'id' => $lead->id,
                'lead_type' => $lead->lead_type->value,
                'lead_type_label' => $lead->lead_type->label(),
                'name' => $lead->name,
                'contact_name' => $lead->contact_name,
                'phone' => $lead->phone,
                'secondary_phone' => $lead->secondary_phone,
                'email' => $lead->email,
                'address' => $lead->address,
                'district' => $lead->district,
                'thana' => $lead->thana,
                'area' => $lead->area,
                'market' => $lead->market,
                'landmark' => $lead->landmark,
                // ⭐ Slice 1's lifecycle, finally on the screen. Unverified is
                // a normal, working state (Joy's decision 1), so it is shown as
                // a fact about the lead, never as an error.
                'is_verified' => $lead->isVerified(),
                'verified_by' => $lead->verifiedBy?->name,
                // ⚠ A date string built on the server, in the app's own Asia/Dhaka
                // clock (C12). A timestamp sliced in the browser printed dates a
                // day early on 13 Sep.
                'verified_on' => $lead->verified_at?->toDateString(),
                'is_archived' => $lead->isArchived(),
                'created_by' => $lead->createdBy?->name,
                'potential' => $lead->potential?->value,
                'importance' => $lead->importance?->value,
                'profile' => $this->profileRow($lead),
                'status' => $lead->status->value,
                'status_label' => $lead->status->label(),
                'brand_id' => $lead->brand_id,
                'brand' => $lead->brand?->name,
                'assigned_to_user_id' => $lead->assigned_to_user_id,
                'assigned_to' => $lead->assignedTo?->name,
                'qualification_note' => $lead->qualification_note,
                'lost_reason_id' => $lead->lost_reason_id,
                'note' => $lead->note,
                // ⚠ Shown so a converted lead is obviously not editable back into
                // the pipeline, and so the link is visible rather than implied.
                'converted_customer' => $lead->convertedCustomer?->name,
                // ⚠ Shown for the same reason as the conversion above: a lead
                // that points at another is not the one to work, and saying so
                // on the row is cheaper than someone chasing the wrong person.
                'duplicate_of_lead_id' => $lead->duplicate_of_lead_id,
                'duplicate_of' => $lead->duplicateOf?->name,
                'created_at' => $lead->created_at?->toDateString(),
            ]);

        return Inertia::render('crm/leads/index', [
            'leads' => $leads,
            'filters' => $filters,
            'statuses' => collect(LeadStatus::cases())
                ->map(fn (LeadStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            // ⚠⚠ C-CRM-9: the FORM's list excludes `converted`. It asserts a
            // customer exists and is set only by the Phase 3 conversion action.
            'selectable_statuses' => collect(LeadStatus::selectable())
                ->map(fn (LeadStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'sources' => LeadSource::active()->orderBy('name')->get(['id', 'name', 'code']),
            'campaigns' => Campaign::active()->orderBy('name')->get(['id', 'name', 'code', 'lead_source_id']),
            'lost_reasons' => Reason::forContext('lead_lost')->active()->orderBy('name')->get(['id', 'name']),
            'agents' => $this->businessUsers($businessId, $request->user()),
            'can_assign' => $request->user()->can('crm.leads.assign'),
            'lead_types' => collect(LeadType::selectable())
                ->map(fn (LeadType $type) => ['value' => $type->value, 'label' => $type->label(), 'is_shop' => $type->isShop()])
                ->values(),
            // ⚠ The dataset's own spellings, so the filter and the form agree
            // with what validation will accept.
            'districts' => collect(BdGeo::districts())->pluck('name')->values(),
            'potentials' => collect(LeadPotential::cases())->map(fn (LeadPotential $p) => ['value' => $p->value, 'label' => $p->label()])->values(),
            'importances' => collect(LeadImportance::cases())->map(fn (LeadImportance $i) => ['value' => $i->value, 'label' => $i->label()])->values(),
            'warehouse_ownerships' => collect(WarehouseOwnership::cases())->map(fn (WarehouseOwnership $w) => ['value' => $w->value, 'label' => $w->label()])->values(),
            // ⚠ Retired entries are sent too, flagged, so a lead that still holds
            // one shows it on edit instead of an empty picker; the form offers
            // only active ones for a new choice.
            'lookups' => collect(CrmLookupList::cases())->mapWithKeys(fn (CrmLookupList $list) => [
                $list->value => CrmLookup::query()->ofList($list)->ordered()->get(['id', 'name', 'is_active']),
            ]),
            // ⚠⚠ Without this prop the convert endpoint had NO caller. The route,
            // the action, the permission and seventeen tests all shipped on
            // 9 August; the screen was never brought forward, so no lead in this
            // system could become a customer. `ConvertLead` is the only writer of
            // `converted_customer_id`, so the funnel simply had no exit.
            'can_convert' => $request->user()->can('crm.leads.convert'),
            // ⚠ Duplicate linking needs it since 15 Sep 2026; without the prop
            // an officer would see a button the route refuses.
            'can_verify' => $request->user()->can('crm.leads.verify'),
            // ⚠ System and inactive customers are excluded because `ConvertLead`
            // REFUSES both - offering a choice the action will reject would be a
            // form that fails on submit for reasons the user cannot see.
            'customers' => Customer::where('is_system', false)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'phone']),
            // ⚠⚠ Straight from the enum, never typed by hand. A case mismatch
            // here - `Distributor` against `distributor` - once charged a
            // distributor the retail price without anyone noticing.
            'customer_types' => collect(CustomerType::cases())
                ->map(fn (CustomerType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
        ]);
    }

    /**
     * Who might match this person already - across BOTH leads and customers.
     *
     * ⚠⚠ C-CRM-13 / D5: WARN AND ALLOW, NEVER BLOCK, and never merge silently.
     * Households share a number, a shop's landline is the owner's mobile, and a
     * family buys under one phone. A hard block would simply make staff invent
     * fake numbers, which destroys the identity data this exists to protect.
     *
     * ⚠ Honest limit of slice 1: this SHOWS the matches so a user can go and use
     * the existing record instead. True record-linking is conversion, and
     * conversion is Phase 3.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $normalized = PhoneIdentity::normalize($request->string('phone')->toString());

        if ($normalized === null) {
            return response()->json(['leads' => [], 'customers' => []]);
        }

        // ⚠ Both queries run inside BusinessScope, so a match can never come from
        // another tenant. That is asserted by a test, not assumed.
        // ⚠ On either phone of the existing lead (slice 2b): a shop's second
        // number typed as somebody's first is the same shop.
        $leads = Lead::query()->reachableOn($normalized)
            ->when($request->integer('ignore_lead_id'), fn ($q, $id) => $q->whereKeyNot($id))
            ->limit(10)
            ->get(['id', 'name', 'phone', 'status'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'status' => $lead->status->value,
            ]);

        // ⭐ Build plan D4 (15 Sep 2026). A lead with this number that the reader
        // may NOT see is still reported, by the name of whoever holds it and
        // nothing else. Silence would send an officer off to create a second
        // record of a shop his colleague already works; showing the lead would
        // hand him exactly what the visibility rule hides.
        $visibility = app(LeadVisibility::class);

        $heldElsewhere = Lead::withoutGlobalScope(LeadVisibilityScope::class)
            ->reachableOn($normalized)
            ->when($request->integer('ignore_lead_id'), fn ($q, $id) => $q->whereKeyNot($id))
            ->with('assignedTo:id,name')
            ->limit(10)
            ->get()
            ->reject(fn (Lead $lead) => $visibility->canSee($request->user(), $lead))
            ->map(fn (Lead $lead) => ['held_by' => $lead->assignedTo?->name])
            ->values();

        $customers = Customer::where('phone_normalized', $normalized)
            ->limit(10)
            ->get(['id', 'name', 'code', 'phone', 'type'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'phone' => $customer->phone,
                'type' => $customer->type->value,
            ]);

        return response()->json(['leads' => $leads, 'held_elsewhere' => $heldElsewhere, 'customers' => $customers]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // ⭐ Build plan D1 (15 Sep 2026). Somebody who may not assign leads owns
        // the lead they capture. Left unassigned, an officer's new shop would
        // vanish from his own list the moment he saved it, because an unassigned
        // lead is visible only to the people who hand leads out.
        if (! $request->user()->can('crm.leads.assign')) {
            $data['lead']['assigned_to_user_id'] = $request->user()->id;
        }

        // ⚠ One transaction: a profile refused after the lead was written (a
        // coverage thana outside its district) must not leave a lead with no
        // profile behind a validation error.
        DB::transaction(function () use ($data, $request) {
            $lead = new Lead($data['lead']);
            $lead->forceFill(['created_by_user_id' => $request->user()->id])->save();

            app(SaveLeadProfile::class)->handle($lead, $data['profile']);

            $this->recordAttribution($lead, $data['attribution'], $request);

            // Standing rhythm: a fresh lead gets its first-call task at once.
            app(ApplyLeadCadences::class)->handle($lead, $request->user());
        });

        return back()->with('success', 'Lead captured.');
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        // ⚠⚠ A converted lead is history. Editing it would let somebody change
        // which campaign a paying customer came from after the fact, which is
        // exactly the retrospective rewrite this design forbids.
        if ($lead->status === LeadStatus::Converted) {
            throw ValidationException::withMessages([
                'status' => 'This lead has been converted to a customer and can no longer be edited.',
            ]);
        }

        $data = $this->validated($request, $lead);

        DB::transaction(function () use ($data, $lead, $request) {
            $lead->update($data['lead']);

            app(SaveLeadProfile::class)->handle($lead, $data['profile']);

            // ⚠ An attribution is appended only when a source is supplied on the
            // edit - "this person came back through another campaign". Nothing is
            // ever overwritten (C-CRM-7).
            if (($data['attribution']['lead_source_id'] ?? null) !== null) {
                $this->recordAttribution($lead, $data['attribution'], $request);
            }
        });

        return back()->with('success', 'Lead updated.');
    }

    /**
     * @param  array<string, mixed>  $attribution
     */
    private function recordAttribution(Lead $lead, array $attribution, Request $request): void
    {
        if (($attribution['lead_source_id'] ?? null) === null) {
            return;
        }

        CrmAttribution::create($attribution + [
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'occurred_at' => $attribution['occurred_at'] ?? now(),
            'recorded_by' => $request->user()->id,
        ]);
    }

    /**
     * ⚠⚠ EVERY user-supplied foreign key is BUSINESS-SCOPED. A bare `exists:`
     * accepts another tenant's id, and this project has shipped that defect
     * twice - `products.category_id`/`unit_of_measure_id` on 5 August, and the
     * ACL-02 bypass before it. C-CRM-1.
     *
     * @return array{lead: array<string, mixed>, attribution: array<string, mixed>}
     */
    /**
     * ⭐⭐ A SHOP'S PHONE NUMBER IS THE SHOP, so two leads may not carry it.
     *
     * Joy's instruction, 10 Sep 2026, and the fear behind it is specific: two
     * field officers each believing they own the same Dokandar. It is scoped to
     * shop-shaped types on his decision of 12 Sep, because a PERSON's number is
     * not an identity in the same way - a household shares one, and refusing the
     * second member of a family would be refusing a real customer. See
     * LeadType::isShop().
     *
     * ⚠⚠ THE REFUSAL NAMES THE RECORD. "Already exists" tells an officer
     * standing in a shop nothing they can act on; the name tells them whether
     * they are looking at their own earlier visit or somebody else's
     * relationship. It is the difference between a rule and an obstruction.
     *
     * ⚠ An ARCHIVED lead still holds the number, and the message says so. If
     * archiving released it, the same officer could archive and re-create one
     * shop endlessly and no duplicate would ever be detected.
     *
     * ⚠ The unique index is still the real guarantee - this runs first purely so
     * a person gets a sentence instead of a database error.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function guardShopPhoneIsNotAlreadyTaken(array $validated, ?Lead $lead, int $businessId, User $reader): void
    {
        $type = LeadType::tryFrom((string) ($validated['lead_type'] ?? '')) ?? $lead?->lead_type ?? LeadType::Customer;

        if (! $type->isShop()) {
            return;
        }

        $key = PhoneIdentity::normalize($validated['phone'] ?? null);

        if ($key === null) {
            return;
        }

        // ⚠⚠ WITHOUT the visibility scope. The number is unique across the whole
        // business, not across what this officer may see: scoped, a shop held by
        // a colleague would pass this check and hit the unique index as a raw
        // database error instead of a sentence.
        $existing = Lead::withoutGlobalScope(LeadVisibilityScope::class)
            ->where('business_id', $businessId)
            ->where('shop_phone_key', $key)
            ->when($lead !== null, fn ($query) => $query->whereKeyNot($lead->getKey()))
            ->first();

        if ($existing === null) {
            return;
        }

        // ⭐ Build plan D4: a lead this person may not see is named by its
        // holder only. Which shop it is, and whether it was archived, belong to
        // the people who can see it.
        if (! app(LeadVisibility::class)->canSee($reader, $existing)) {
            $holder = $existing->assignedTo?->name ?? 'nobody yet';

            throw ValidationException::withMessages([
                'phone' => "This number already belongs to a lead held by {$holder}.",
            ]);
        }

        $who = filled($existing->name)
            ? $existing->name
            : 'another '.$existing->lead_type->label().' lead';

        throw ValidationException::withMessages([
            'phone' => $existing->isArchived()
                ? "This number already belongs to {$who}, which has been archived. Restore it instead of creating a second one."
                : "This number already belongs to {$who}.",
        ]);
    }

    private function validated(Request $request, ?Lead $lead = null): array
    {
        $businessId = $lead?->business_id ?? app(BusinessContext::class)->id();

        // The type decides whether a location is required, so it is read before
        // the rules are built. An edit that does not send one keeps the lead's.
        $type = LeadType::tryFrom((string) $request->input('lead_type')) ?? $lead?->lead_type ?? LeadType::Customer;

        $validated = $request->validate([
            // ⚠ Nullable: C-CRM-16 keeps every personal field blankable so a
            // future privacy rule can anonymise without destroying history.
            'name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'secondary_phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],

            // ⭐ Build plan D8 (15 Sep 2026): a shop, a distributor and a supplier
            // are PLACES, and a place with no district and thana is one nobody
            // can visit or count. A consumer's location stays optional.
            'district' => [Rule::requiredIf($type->isShop()), 'nullable', 'string', 'max:60'],
            'thana' => [Rule::requiredIf($type->isShop()), 'nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:120'],
            'market' => ['nullable', 'string', 'max:160'],
            'landmark' => ['nullable', 'string', 'max:255'],

            // ⭐ Joy's answers 5 and 6: the officer's estimate and an A/B/C
            // importance, for retailers and distributors (nulled for the rest
            // below, so a consumer can never be rated by a stale form value).
            'potential' => ['nullable', Rule::enum(LeadPotential::class)],
            'importance' => ['nullable', Rule::enum(LeadImportance::class)],

            ...SaveLeadProfile::rules($businessId),

            // ⚠ Nullable rather than required, although every lead has a type.
            // The column and the model both default to `customer`, so a capture
            // path that does not yet offer the selector keeps working and keeps
            // meaning exactly what it meant before. Required here would have
            // broken every existing one the day this shipped.
            'lead_type' => ['nullable', Rule::in(collect(LeadType::selectable())->map(fn ($t) => $t->value))],

            // ⚠⚠ C-CRM-9: `converted` is NOT in the accepted list. It asserts a
            // customer exists and belongs to the Phase 3 action alone.
            'status' => ['required', Rule::in(collect(LeadStatus::selectable())->map(fn ($s) => $s->value))],

            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('business_id', $businessId)],

            // ⚠⚠ C-CRM-2 / D3: `users` has NO `business_id` column - membership
            // is the `business_user` PIVOT. A scoped Rule::exists on users would
            // match NOTHING and silently reject every valid agent. Membership is
            // therefore checked against the pivot instead.
            'assigned_to_user_id' => [
                'nullable',
                Rule::exists('business_user', 'user_id')->where('business_id', $businessId),
            ],

            'qualification_note' => ['nullable', 'string'],

            // ⚠⚠ C-CRM-10 / D6: in the business AND with the right context, or a
            // stock-adjustment reason could be chosen as a lead's lost reason.
            'lost_reason_id' => [
                'nullable',
                Rule::exists('reasons', 'id')->where('business_id', $businessId)->where('context', 'lead_lost'),
            ],
            'note' => ['nullable', 'string'],

            // ── attribution, optional on create and on edit ──────────────────
            'lead_source_id' => ['nullable', Rule::exists('lead_sources', 'id')->where('business_id', $businessId)],
            'campaign_id' => ['nullable', Rule::exists('campaigns', 'id')->where('business_id', $businessId)],
            'ad_set' => ['nullable', 'string', 'max:255'],
            'ad_name' => ['nullable', 'string', 'max:255'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'external_lead_id' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        // ⚠ A campaign must belong to the source it is recorded under, or the
        // two axes disagree and "which source produced this" answers twice.
        if (($validated['campaign_id'] ?? null) !== null) {
            $campaign = Campaign::find($validated['campaign_id']);

            if ($campaign !== null && (int) $campaign->lead_source_id !== (int) ($validated['lead_source_id'] ?? 0)) {
                throw ValidationException::withMessages([
                    'campaign_id' => 'That campaign does not belong to the selected source.',
                ]);
            }
        }

        // ⚠ Assignment is a separate permission from capture (C-CRM-14). Staff
        // may work leads; deciding whose workload they land in is a manager's.
        if (! $request->user()->can('crm.leads.assign')) {
            unset($validated['assigned_to_user_id']);
        }

        $this->guardAssigneeIsWithinReach($validated, $request->user());

        $validated = $this->placeOnTheMap($validated);

        $this->guardShopPhoneIsNotAlreadyTaken($validated, $lead, $businessId, $request->user());

        $leadFields = collect($validated)->only([
            'name', 'contact_name', 'phone', 'secondary_phone', 'email', 'address',
            'district', 'thana', 'area', 'market', 'landmark', 'potential', 'importance',
            'status', 'lead_type', 'brand_id',
            'assigned_to_user_id', 'qualification_note', 'lost_reason_id', 'note',
        ])->all();

        if (! in_array($type, [LeadType::Retailer, LeadType::Distributor], true)) {
            $leadFields['potential'] = null;
            $leadFields['importance'] = null;
        }

        // ⚠ A lead marked lost is timestamped once, when it is lost.
        if (($leadFields['status'] ?? null) === LeadStatus::Lost->value && $lead?->lost_at === null) {
            $leadFields['lost_at'] = now();
        }

        return [
            'lead' => $leadFields,
            'profile' => collect($validated)->only([
                ...SaveLeadProfile::RETAILER_FIELDS,
                ...SaveLeadProfile::DISTRIBUTOR_FIELDS,
                ...SaveLeadProfile::SUPPLIER_FIELDS,
            ])->all(),
            'attribution' => collect($validated)->only([
                'lead_source_id', 'campaign_id', 'ad_set', 'ad_name', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_content', 'utm_term', 'external_lead_id', 'occurred_at',
            ])->all(),
        ];
    }

    /**
     * The profile of the lead's CURRENT type, flat, in the shape the form posts.
     *
     * ⚠ Only the current type's: a retailer corrected to a distributor keeps its
     * shop details in the database, but the edit form must not post them back
     * under the distributor's name.
     *
     * @return array<string, mixed>
     */
    private function profileRow(Lead $lead): array
    {
        return match ($lead->lead_type) {
            LeadType::Retailer => [
                'shop_type_id' => $lead->retailerProfile?->shop_type_id,
                'shop_type' => $lead->retailerProfile?->shopType?->name,
                'current_supplier' => $lead->retailerProfile?->current_supplier,
                'wants_credit' => match ($lead->retailerProfile?->wants_credit) {
                    true => 'yes',
                    false => 'no',
                    default => null,
                },
                'competitor_brand_ids' => $lead->competitorBrands->pluck('id')->values()->all(),
                'competitor_brands' => $lead->competitorBrands->pluck('name')->values()->all(),
            ],
            LeadType::Distributor => [
                'business_nature' => $lead->distributorProfile?->business_nature,
                'other_companies' => $lead->distributorProfile?->other_companies,
                'warehouse_count' => $lead->distributorProfile?->warehouse_count,
                'warehouse_ownership' => $lead->distributorProfile?->warehouse_ownership?->value,
                'vehicle_count' => $lead->distributorProfile?->vehicle_count,
                'sales_staff_count' => $lead->distributorProfile?->sales_staff_count,
                'coverage' => $lead->coverageAreas->map(fn ($a) => ['district' => $a->district, 'thana' => $a->thana])->values()->all(),
            ],
            LeadType::Supplier => [
                'supplier_type_id' => $lead->supplierProfile?->supplier_type_id,
                'supplier_type' => $lead->supplierProfile?->supplierType?->name,
                'supply_categories' => $lead->supplierProfile?->supply_categories,
                'origin_district' => $lead->supplierProfile?->origin_district,
            ],
            LeadType::Customer => [],
        };
    }

    /**
     * ⚠ The assign list on the screen is narrowed to the reader's own team, and
     * a list is a suggestion: the POST is what has to refuse. Without this an
     * ASM could hand a lead to anybody in the business by editing the request,
     * and it would disappear from his own list the moment he saved.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function guardAssigneeIsWithinReach(array $validated, User $reader): void
    {
        $assignee = $validated['assigned_to_user_id'] ?? null;
        $visibility = app(LeadVisibility::class);

        if ($assignee === null || $visibility->seesEverything($reader)) {
            return;
        }

        if (! in_array((int) $assignee, $visibility->userIdsFor($reader), true)) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'You can hand a lead only to yourself or somebody in your team.',
            ]);
        }
    }

    /**
     * District, thana and area in the dataset's own spelling, or a refusal.
     *
     * ⭐ Stored as BdGeo spells them, so "Chittagong" typed by one officer and
     * "Chattogram" picked by another are one place in every filter and count.
     * Each level is checked INSIDE the one above it: a thana that exists, but
     * in another district, is refused rather than quietly describing nowhere.
     *
     * ⚠ With no dataset loaded (config `geo.dataset` empty) geography has no
     * opinion, and what was typed is kept as typed - the same "ask, never
     * guess" fallback every other BdGeo caller uses.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function placeOnTheMap(array $validated): array
    {
        if (BdGeo::districts() === []) {
            return $validated;
        }

        $district = $validated['district'] ?? null;
        $thana = $validated['thana'] ?? null;
        $area = $validated['area'] ?? null;

        if (filled($district)) {
            $validated['district'] = BdGeo::districtNamed($district)
                ?? throw ValidationException::withMessages(['district' => 'Pick the district from the list.']);
        } elseif (filled($thana) || filled($area)) {
            throw ValidationException::withMessages(['district' => 'Pick the district first.']);
        }

        if (filled($thana)) {
            $validated['thana'] = BdGeo::thanaNamed($validated['district'], $thana)
                ?? throw ValidationException::withMessages(['thana' => 'Pick a thana of '.$validated['district'].' from the list.']);
        } elseif (filled($area)) {
            throw ValidationException::withMessages(['thana' => 'Pick the thana first.']);
        }

        if (filled($area)) {
            $validated['area'] = BdGeo::areaNamed($validated['district'], $validated['thana'], $area)
                ?? throw ValidationException::withMessages(['area' => 'Pick an area of '.$validated['thana'].' from the list, or leave it empty.']);
        }

        return $validated;
    }

    /**
     * The people this reader may hand a lead to, or filter the list by.
     *
     * ⭐ Everybody in the business for somebody who sees every lead; otherwise
     * themself and their team (15 Sep 2026). Two reasons, one rule: an ASM who
     * hands a lead to somebody outside his team would watch it vanish from his
     * own list, and an officer's filter listing every login in the business is a
     * staff directory he has no use for.
     *
     * @return Collection<int, array{id:int, name:string}>
     */
    private function businessUsers(int $businessId, User $reader): Collection
    {
        $visibility = app(LeadVisibility::class);

        return User::whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
            ->when(! $visibility->seesEverything($reader), fn ($q) => $q->whereIn('users.id', $visibility->userIdsFor($reader)))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * Two leads are one person.
     *
     * ⚠ Gated by `crm.leads.verify` since 15 Sep 2026, not `crm.leads.manage`.
     * It creates nothing outside CRM, but it takes a lead out of the working
     * list, and Joy reserved merging for the ASM (redesign decision 1). Left on
     * the officer's permission, an officer could hide a colleague's shop behind
     * his own by calling it a duplicate.
     */
    public function linkDuplicate(Request $request, Lead $lead, LinkDuplicateLead $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'survivor_id' => ['required', 'integer',
                Rule::exists('leads', 'id')->where('business_id', $businessId)],
        ]);

        $action->handle(
            duplicate: $lead,
            survivor: Lead::findOrFail($data['survivor_id']),
            mergedBy: $request->user(),
        );

        return back()->with('success', 'Marked as a duplicate. Nothing was deleted.');
    }

    /**
     * ⭐ The ASM confirming a field-created lead is a real place.
     *
     * ⚠ Its own permission rather than `crm.leads.manage`, and that separation
     * is the whole design: the officer who created the lead may keep working it,
     * correct its phone and record a visit without waiting for anybody, but
     * cannot be the person who says it is genuine. Same preparer/approver split
     * the payroll and campaign-send paths already use.
     */
    public function verify(Request $request, Lead $lead, VerifyLead $action): RedirectResponse
    {
        $action->handle($lead, $request->user());

        return back()->with('success', 'Lead verified.');
    }

    public function unverify(Request $request, Lead $lead, VerifyLead $action): RedirectResponse
    {
        $action->undo($lead);

        return back()->with('success', 'Verification withdrawn.');
    }

    /**
     * ⛔ Archiving, because there is no delete. See ArchiveLead.
     */
    public function archive(Request $request, Lead $lead, ArchiveLead $action): RedirectResponse
    {
        $action->handle($lead, $request->user());

        return back()->with('success', 'Lead archived. Nothing was deleted.');
    }

    public function restore(Request $request, Lead $lead, ArchiveLead $action): RedirectResponse
    {
        $action->restore($lead);

        return back()->with('success', 'Lead restored.');
    }

    /**
     * A prospect becomes a customer - either one we already have, or a new one.
     *
     * ⚠⚠ Every rule lives in `ConvertLead`. This validates SHAPE and ownership
     * and hands over, because the action is the single writer of
     * `converted_customer_id` and a second set of rules here would be a second
     * conversion path that disagrees with the first.
     */
    public function convert(Request $request, Lead $lead, ConvertLead $action): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            // ⚠ Scoped exists. A bare rule would accept another business's
            // customer, and the global scope would then hide the row the lead
            // points at.
            'customer_id' => ['nullable', 'integer',
                Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'code' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            // ⚠ Against the enum since 15 Sep 2026. A free string reached the
            // customer's enum cast and failed as a 500 on save instead of a
            // sentence under the field.
            'type' => ['nullable', Rule::enum(CustomerType::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
        ]);

        $customer = $action->handle(
            lead: $lead,
            convertedBy: $request->user(),
            existing: empty($data['customer_id']) ? null : Customer::findOrFail($data['customer_id']),
            newCustomer: $data,
        );

        return back()->with('success', "Converted to customer {$customer->code}.");
    }
}
