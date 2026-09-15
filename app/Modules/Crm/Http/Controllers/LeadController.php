<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Actions\ArchiveLead;
use App\Modules\Crm\Actions\ConvertLead;
use App\Modules\Crm\Actions\LinkDuplicateLead;
use App\Modules\Crm\Actions\VerifyLead;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmAttribution;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        ];

        $leads = Lead::query()
            ->with(['brand:id,name,code', 'assignedTo:id,name', 'convertedCustomer:id,name,code', 'duplicateOf:id,name'])
            // ⚠ Searching the NORMALISED phone as well as the raw one. A user who
            // types `01711000000` must find a lead captured as `+8801711000000`,
            // or duplicate detection is theatre.
            ->when($filters['search'], function ($query, $search) {
                $normalized = PhoneIdentity::normalize($search);

                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->when($normalized, fn ($inner) => $inner->orWhere('phone_normalized', 'like', "%{$normalized}%")));
            })
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
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'address' => $lead->address,
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
            'agents' => $this->businessUsers($businessId),
            'can_assign' => $request->user()->can('crm.leads.assign'),
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
        $leads = Lead::where('phone_normalized', $normalized)
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
            ->where('phone_normalized', $normalized)
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

        $lead = new Lead($data['lead']);
        $lead->forceFill(['created_by_user_id' => $request->user()->id])->save();

        $this->recordAttribution($lead, $data['attribution'], $request);

        // Standing rhythm: a fresh lead gets its first-call task at once.
        app(ApplyLeadCadences::class)->handle($lead, $request->user());

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

        $lead->update($data['lead']);

        // ⚠ An attribution is appended only when a source is supplied on the
        // edit - "this person came back through another campaign". Nothing is
        // ever overwritten (C-CRM-7).
        if (($data['attribution']['lead_source_id'] ?? null) !== null) {
            $this->recordAttribution($lead, $data['attribution'], $request);
        }

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

        $validated = $request->validate([
            // ⚠ Nullable: C-CRM-16 keeps every personal field blankable so a
            // future privacy rule can anonymise without destroying history.
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],

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

        $this->guardShopPhoneIsNotAlreadyTaken($validated, $lead, $businessId, $request->user());

        $leadFields = collect($validated)->only([
            'name', 'phone', 'email', 'address', 'status', 'lead_type', 'brand_id',
            'assigned_to_user_id', 'qualification_note', 'lost_reason_id', 'note',
        ])->all();

        // ⚠ A lead marked lost is timestamped once, when it is lost.
        if (($leadFields['status'] ?? null) === LeadStatus::Lost->value && $lead?->lost_at === null) {
            $leadFields['lost_at'] = now();
        }

        return [
            'lead' => $leadFields,
            'attribution' => collect($validated)->only([
                'lead_source_id', 'campaign_id', 'ad_set', 'ad_name', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_content', 'utm_term', 'external_lead_id', 'occurred_at',
            ])->all(),
        ];
    }

    /**
     * Users who belong to this business, through the pivot.
     *
     * @return Collection<int, array{id:int, name:string}>
     */
    private function businessUsers(int $businessId): Collection
    {
        return User::whereHas('businesses', fn ($q) => $q->where('businesses.id', $businessId))
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
            'type' => ['nullable', 'string', 'max:30'],
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
