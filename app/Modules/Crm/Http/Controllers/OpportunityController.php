<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opportunities + the pipeline board (Module 4, Slice 1; CRM spec sections
 * 7 and 8).
 *
 * A deal belongs to a lead or a customer, sits in exactly one stage, and
 * ends as an OUTCOME: won, or lost with a reason. The board shows open deals
 * only - won and lost are history, never columns to park things in.
 */
class OpportunityController extends Controller
{
    private const DEFAULT_STAGES = ['Qualification', 'Proposal', 'Negotiation', 'Closing'];

    public function index(): Response
    {
        $businessId = app(BusinessContext::class)->id();

        // First visit plants the default stages, idempotently. Renaming and
        // reordering can come later; an empty board teaches nobody anything.
        foreach (self::DEFAULT_STAGES as $order => $name) {
            DB::table('crm_pipeline_stages')->updateOrInsert(
                ['business_id' => $businessId, 'name' => $name],
                ['sort_order' => $order, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // ⚠⚠ Deals follow the same rule as leads (15 Sep 2026): the Owner sees
        // every deal, everybody else their own and their team's. A board that
        // hid the lead's name but still showed a colleague's deal, its value
        // and its next step would leak the very thing the lead list now hides.
        $open = DB::table('crm_opportunities')
            ->where('business_id', $businessId)
            ->where('status', 'open')
            ->tap(fn ($q) => $this->visibleDeals($q))
            ->orderByDesc('value')
            ->get();

        $leadNames = Lead::query()->whereIn('id', $open->pluck('lead_id')->filter())->pluck('name', 'id');
        $customerNames = Customer::query()->whereIn('id', $open->pluck('customer_id')->filter())->pluck('name', 'id');
        $userNames = DB::table('users')->whereIn('id', $open->pluck('assigned_to_user_id')->filter())->pluck('name', 'id');

        $stages = DB::table('crm_pipeline_stages')
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($stage) use ($open, $leadNames, $customerNames, $userNames) {
                $cards = $open->where('stage_id', $stage->id)->values();

                return [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'total_value' => (float) $cards->sum('value'),
                    'opportunities' => $cards->map(fn ($opportunity) => [
                        'id' => $opportunity->id,
                        'name' => $opportunity->name,
                        'party' => $opportunity->customer_id
                            ? ($customerNames[$opportunity->customer_id] ?? '?')
                            : ($leadNames[$opportunity->lead_id] ?? '?'),
                        'is_customer' => $opportunity->customer_id !== null,
                        'value' => $opportunity->value,
                        'expected_close_on' => $opportunity->expected_close_on,
                        'assigned_to' => $userNames[$opportunity->assigned_to_user_id] ?? null,
                        'next_action' => $opportunity->next_action,
                    ]),
                ];
            });

        $recentlyClosed = DB::table('crm_opportunities')
            ->where('business_id', $businessId)
            ->whereIn('status', ['won', 'lost'])
            ->tap(fn ($q) => $this->visibleDeals($q))
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get()
            ->map(fn ($opportunity) => [
                'id' => $opportunity->id,
                'name' => $opportunity->name,
                'status' => $opportunity->status,
                'value' => $opportunity->value,
                'closed_at' => $opportunity->won_at ?? $opportunity->lost_at,
                'lost_reason' => $opportunity->lost_reason_id
                    ? DB::table('reasons')->where('id', $opportunity->lost_reason_id)->value('name')
                    : null,
            ]);

        return Inertia::render('crm/pipeline', [
            'stages' => $stages,
            'recentlyClosed' => $recentlyClosed,
            'leads' => Lead::query()->whereNull('converted_customer_id')->whereNull('merged_at')
                ->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
            'lostReasons' => Reason::forContext('lead_lost')->active()->orderBy('name')->get(['id', 'name']),
            'users' => DB::table('users')
                ->join('business_user', 'users.id', '=', 'business_user.user_id')
                ->where('business_user.business_id', $businessId)
                ->orderBy('users.name')->get(['users.id', 'users.name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            // A deal belongs to SOMEBODY: a lead still being courted, or an
            // existing customer being grown.
            'lead_id' => ['nullable', 'integer', 'required_without:customer_id', Rule::exists('leads', 'id')->where('business_id', $businessId)],
            'customer_id' => ['nullable', 'integer', 'required_without:lead_id', Rule::exists('customers', 'id')->where('business_id', $businessId)],
            'stage_id' => ['required', 'integer', Rule::exists('crm_pipeline_stages', 'id')->where('business_id', $businessId)],
            'value' => ['required', 'numeric', 'min:0'],
            'expected_close_on' => ['nullable', 'date'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        // ⚠ Build plan D1, as for leads: somebody who may not assign owns the deal
        // they put on the board, or it would vanish from their own view on save.
        if (! $request->user()->can('crm.leads.assign')) {
            $data['assigned_to_user_id'] = $request->user()->id;
        }

        DB::table('crm_opportunities')->insert($data + [
            'business_id' => $businessId,
            'status' => 'open',
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Deal is on the board.');
    }

    public function stage(Request $request, int $opportunity): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();
        $row = $this->openDeal($opportunity, $businessId);

        $data = $request->validate([
            'stage_id' => ['required', 'integer', Rule::exists('crm_pipeline_stages', 'id')->where('business_id', $businessId)],
        ]);

        DB::table('crm_opportunities')->where('id', $row->id)
            ->update(['stage_id' => $data['stage_id'], 'updated_at' => now()]);

        return back();
    }

    public function won(int $opportunity): RedirectResponse
    {
        $row = $this->openDeal($opportunity, app(BusinessContext::class)->id());

        DB::table('crm_opportunities')->where('id', $row->id)->update([
            'status' => 'won', 'won_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Won. Now the order desk takes over.');
    }

    public function lost(Request $request, int $opportunity): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();
        $row = $this->openDeal($opportunity, $businessId);

        // Losing without a reason is how the same deal gets lost twice.
        $data = $request->validate([
            'lost_reason_id' => ['required', 'integer',
                Rule::exists('reasons', 'id')->where('business_id', $businessId)->where('context', 'lead_lost'),
            ],
        ]);

        DB::table('crm_opportunities')->where('id', $row->id)->update([
            'status' => 'lost', 'lost_at' => now(),
            'lost_reason_id' => $data['lost_reason_id'], 'updated_at' => now(),
        ]);

        return back()->with('success', 'Marked lost, reason kept.');
    }

    private function openDeal(int $id, int $businessId): object
    {
        $row = DB::table('crm_opportunities')
            ->where('business_id', $businessId)
            ->where('id', $id)
            ->where('status', 'open')
            ->tap(fn ($q) => $this->visibleDeals($q))
            ->first();
        abort_if($row === null, 404);

        return $row;
    }

    /**
     * ⚠ The lead rule applied to the deal's own assignee. See LeadVisibility.
     */
    private function visibleDeals(Builder $query): void
    {
        app(LeadVisibility::class)->restrict($query, request()->user(), 'crm_opportunities.assigned_to_user_id');
    }
}
