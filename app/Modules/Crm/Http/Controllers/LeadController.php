<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Actions\ArchiveLead;
use App\Modules\Crm\Actions\ConvertLead;
use App\Modules\Crm\Actions\ConvertLeadToSupplier;
use App\Modules\Crm\Actions\LinkDuplicateLead;
use App\Modules\Crm\Actions\SaveLeadProfile;
use App\Modules\Crm\Actions\VerifyLead;
use App\Modules\Crm\Enums\LeadImportance;
use App\Modules\Crm\Enums\LeadPotential;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CrmAttribution;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Scopes\LeadVisibilityScope;
use App\Modules\Crm\Support\ApplyLeadCadences;
use App\Modules\Crm\Support\GoingCold;
use App\Modules\Crm\Support\LeadFormOptions;
use App\Modules\Crm\Support\LeadPresenter;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\IdentityDocuments;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Support\BdGeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            // ⭐ Round 3 quick views: 'today' = a follow-up due today or overdue.
            'follow_up' => $request->string('follow_up')->toString(),
            // '1' = going cold (GoingCold holds the rule).
            'cold' => $request->string('cold')->toString(),
        ];

        $leads = LeadPresenter::withListColumns(Lead::query()->with(LeadPresenter::RELATIONS))
            ->tap(fn ($query) => $this->applyFilters($query, $filters))
            ->tap(fn ($query) => $this->applyQuickView($query, $filters))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Lead $lead) => LeadPresenter::row($lead));

        return Inertia::render('crm/leads/index', [
            'leads' => $leads,
            'filters' => $filters,
            'views' => $this->quickViewCounts($filters),
            // The app's own date, so "today" on the rows is Dhaka's today.
            'today' => now()->toDateString(),
            'statuses' => collect(LeadStatus::cases())
                ->map(fn (LeadStatus $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            // Edit, Add a follow-up and the status change write with crm.leads.manage.
            'can_manage' => $request->user()->can('crm.leads.manage'),
            ...LeadFormOptions::for($request->user(), $businessId),
        ]);
    }

    /**
     * The filters of the bar above the list: who, where, what state. The quick
     * views (applyQuickView) come on top, so a chip's count can be asked with
     * the same bar.
     *
     * @param  Builder<Lead>  $query
     * @param  array<string, string>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
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
            ->when($filters['lead_type'], fn ($query, $type) => $query->where('lead_type', $type))
            ->when($filters['district'], fn ($query, $district) => $query->where('district', $district))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['brand_id'], fn ($query, $brandId) => $brandId === 'none'
                ? $query->whereNull('brand_id')
                : $query->where('brand_id', $brandId))
            ->when($filters['assigned_to_user_id'], fn ($query, $userId) => $userId === 'none'
                ? $query->whereNull('assigned_to_user_id')
                : $query->where('assigned_to_user_id', $userId));
    }

    /**
     * The quick view the chips choose.
     *
     * ⭐ The WORKING list by default (15 Sep 2026). An archived lead and a
     * duplicate are both "somebody decided nobody should act on this", and an
     * officer scrolling past them on a phone in a bazaar is an officer who calls
     * the wrong record. Both stay one chip away.
     *
     * @param  Builder<Lead>  $query
     * @param  array<string, string>  $filters
     */
    private function applyQuickView(Builder $query, array $filters): void
    {
        $query
            ->when($filters['show'] === '', fn ($query) => $query->workable())
            ->when($filters['show'] === 'duplicates', fn ($query) => $query->whereNotNull('duplicate_of_lead_id'))
            ->when($filters['show'] === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($filters['verification'] === 'verified', fn ($query) => $query->whereNotNull('verified_at'))
            ->when($filters['verification'] === 'unverified', fn ($query) => $query->whereNull('verified_at'))
            ->when($filters['follow_up'] === 'today', fn ($query) => $this->followUpDueToday($query))
            ->when($filters['cold'] === '1', fn ($query) => GoingCold::apply($query));
    }

    /**
     * A lead with an open follow-up due by the end of today, overdue ones
     * included: the officer's list for the day.
     *
     * @param  Builder<Lead>  $query
     */
    private function followUpDueToday(Builder $query): void
    {
        $query->whereExists(fn ($tasks) => $tasks->selectRaw('1')->from('crm_tasks')
            ->where('crm_tasks.subject_type', (new Lead)->getMorphClass())
            ->whereColumn('crm_tasks.subject_id', 'leads.id')
            ->where('crm_tasks.status', TaskStatus::Open->value)
            ->where('crm_tasks.due_at', '<=', now()->endOfDay()->toDateTimeString()));
    }

    /**
     * How many leads each chip would show, with the bar's other filters
     * applied, inside this reader's visibility.
     *
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    private function quickViewCounts(array $filters): array
    {
        $base = fn () => Lead::query()->tap(fn ($query) => $this->applyFilters($query, $filters));

        return [
            'working' => $base()->workable()->count(),
            'today' => $base()->workable()->tap(fn ($query) => $this->followUpDueToday($query))->count(),
            'unverified' => $base()->workable()->whereNull('verified_at')->count(),
            'cold' => GoingCold::apply($base())->count(),
            'duplicates' => $base()->whereNotNull('duplicate_of_lead_id')->count(),
            'archived' => $base()->whereNotNull('archived_at')->count(),
            'all' => $base()->count(),
        ];
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
                'status' => 'This lead has been converted and can no longer be edited.',
            ]);
        }

        $data = $this->validated($request, $lead);

        DB::transaction(function () use ($data, $lead, $request) {
            $previousOfficer = $lead->assigned_to_user_id;

            $lead->update($data['lead']);

            // ⚠ Bug hunt 16 Oct 2026: a lead handed to another officer left its
            // open follow-ups with the old one, who could no longer open the
            // lead (404 on each) and was still reminded every morning. The open
            // follow-ups that were the old officer's go with the lead; ones
            // given to somebody else stay where they are.
            if ($lead->wasChanged('assigned_to_user_id') && $lead->assigned_to_user_id !== null && $previousOfficer !== null) {
                CrmTask::query()
                    ->whereIn('subject_type', array_unique([$lead->getMorphClass(), Lead::class]))
                    ->where('subject_id', $lead->id)
                    ->where('status', 'open')
                    ->where('assigned_to_user_id', $previousOfficer)
                    ->update(['assigned_to_user_id' => $lead->assigned_to_user_id, 'updated_at' => now()]);
            }

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
    public function convert(Request $request, Lead $lead, ConvertLead $action, ConvertLeadToSupplier $toSupplier): RedirectResponse
    {
        // ⭐ Plan 2e: conversion follows the lead's type. A supplier lead becomes
        // a supplier; every other type becomes a customer, and ConvertLead
        // refuses a supplier lead even if something called it directly.
        if ($lead->lead_type === LeadType::Supplier) {
            return $this->convertToSupplier($request, $lead, $toSupplier);
        }

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
            'type' => ['nullable', Rule::enum(CustomerType::class)->only(CustomerType::pickable())],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            // ⭐ CRM slice 2e: the customer follows the lead's type. Same shapes
            // as the customer screen (CustomerController::validated), so a
            // customer made by conversion can hold nothing a typed one cannot.
            'contact_name' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:50'],
            'tin' => ['nullable', 'string', 'max:50'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'price_group_id' => ['nullable', 'integer',
                Rule::exists('price_groups', 'id')->where('business_id', $businessId)],
            // ⭐ Plan D11: trade licence and NID scans, optional.
            ...IdentityDocuments::rules(),
        ]);

        $customer = $action->handle(
            lead: $lead,
            convertedBy: $request->user(),
            existing: empty($data['customer_id']) ? null : Customer::findOrFail($data['customer_id']),
            newCustomer: $data,
            documents: $request->file('documents', []),
        );

        return back()->with('success', "Converted to customer {$customer->code}.");
    }

    /**
     * ⭐ Plan D12: a supplier lead becomes a supplier, or is linked to one we
     * already buy from. Every rule lives in ConvertLeadToSupplier.
     *
     * ⚠⚠ The permission is checked BEFORE the request is read. An officer or an
     * ASM without purchasing.suppliers.manage must get a refusal, not a list of
     * validation errors that tells him what a supplier record holds.
     */
    private function convertToSupplier(Request $request, Lead $lead, ConvertLeadToSupplier $action): RedirectResponse
    {
        abort_unless($this->mayConvertSuppliers($request->user()), 403);

        $businessId = app(BusinessContext::class)->id();

        // Same shapes as SupplierController::validated for these fields.
        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            ...IdentityDocuments::rules(),
        ]);

        $supplier = $action->handle(
            lead: $lead,
            convertedBy: $request->user(),
            existing: empty($data['supplier_id']) ? null : Supplier::findOrFail($data['supplier_id']),
            newSupplier: $data,
            documents: $request->file('documents', []),
        );

        return back()->with('success', "Converted to supplier {$supplier->code}.");
    }

    private function mayConvertSuppliers(?User $user): bool
    {
        return LeadFormOptions::mayConvertSuppliers($user);
    }
}
