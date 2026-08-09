<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\InterestStatus;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\CrmProductInterest;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the CRM can honestly tell management.
 *
 * ⚠⚠ NOTHING HERE IS CACHED OR STORED. Every figure is derived on read, so no
 * report can drift from the records it came from. That is the same rule the
 * ledger follows - a balance is a question asked of the journal lines, never a
 * column somebody keeps in step.
 *
 * ⛔ NOT A SECOND REPORT FRAMEWORK. `App\Modules\Hrm\Reporting` already provides
 * parameterised, exportable, queued reports; promoting its generic half to
 * Foundation is a 59-file extraction with real HRM coupling and is deliberately
 * NOT done here. See CRM-REPORTING-FOUNDATIONS.md §1.
 */
class CrmReportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('crm/reports', [
            'funnel' => $this->funnel(),
            'sources' => $this->sourcePerformance(),
            'demand' => $this->unmetDemand(),
            'followUps' => $this->followUpLoad($request),
            'canSeeAllTasks' => $request->user()?->can('crm.tasks.view.all') ?? false,
        ]);
    }

    /**
     * Where leads stall.
     *
     * ⚠⚠ DUPLICATES ARE EXCLUDED. A lead linked to a survivor is the same person
     * counted twice, and a funnel that double-counts lies about the conversion
     * rate in the direction that flatters it.
     */
    private function funnel(): array
    {
        $counts = Lead::query()->workable()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stages = [];

        foreach (LeadStatus::cases() as $status) {
            $stages[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        $workable = Lead::query()->workable()->count();
        $converted = Lead::query()->workable()->where('status', LeadStatus::Converted)->count();

        return [
            'stages' => $stages,
            // ⚠ C-CRMR-3. The denominator is shown, not implied. A bare
            // percentage invites the reader to guess what it divided by.
            'workable' => $workable,
            'converted' => $converted,
            'duplicatesExcluded' => Lead::query()->whereNotNull('duplicate_of_lead_id')->count(),
            'conversionRate' => $workable === 0 ? null : round($converted / $workable * 100, 1),
        ];
    }

    /**
     * Which source or campaign actually produces customers.
     *
     * ⚠⚠ ATTRIBUTION IS READ THROUGH THE LEAD, including for customers. Nothing
     * was copied onto the customer at conversion, so a report that looked at
     * customer-side attribution would silently return zero for everything.
     */
    private function sourcePerformance(): array
    {
        return Lead::query()
            ->workable()
            ->join('crm_attributions', function ($join) {
                $join->on('crm_attributions.subject_id', '=', 'leads.id')
                    ->where('crm_attributions.subject_type', '=', (new Lead)->getMorphClass());
            })
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'crm_attributions.lead_source_id')
            ->leftJoin('campaigns', 'campaigns.id', '=', 'crm_attributions.campaign_id')
            ->selectRaw('
                COALESCE(lead_sources.name, "Not recorded") as source,
                COALESCE(campaigns.name, "-") as campaign,
                COUNT(DISTINCT leads.id) as leads,
                COUNT(DISTINCT CASE WHEN leads.converted_customer_id IS NOT NULL THEN leads.id END) as converted
            ')
            ->groupBy('source', 'campaign')
            ->orderByDesc('leads')
            ->get()
            ->map(fn ($row) => [
                'source' => $row->source,
                'campaign' => $row->campaign,
                'leads' => (int) $row->leads,
                'converted' => (int) $row->converted,
                'rate' => (int) $row->leads === 0 ? null : round($row->converted / $row->leads * 100, 1),
            ])
            ->all();
    }

    /**
     * What people are asking for.
     *
     * ⚠⚠ COUNTS DISTINCT PEOPLE, NEVER ROWS. Every request is its own dated row,
     * so counting rows counts conversations: "six people want mango" and "one
     * person asked six times" are different businesses, and only one of them is
     * a reason to buy stock.
     *
     * ⚠ Inactive and unstocked products are surfaced loudly rather than
     * filtered. Somebody asking for last season's mango is the signal that
     * decides whether to stock it again - the reason this table exists.
     */
    private function unmetDemand(): array
    {
        $rows = CrmProductInterest::query()
            ->where('status', InterestStatus::Open)
            ->with(['product:id,sku,name,is_active', 'category:id,name', 'brand:id,name'])
            ->get();

        return $rows
            ->groupBy(fn (CrmProductInterest $i) => $i->product_id !== null
                ? 'product:'.$i->product_id
                : ($i->category_id !== null ? 'category:'.$i->category_id : 'brand:'.$i->brand_id))
            ->map(function ($group) {
                /** @var CrmProductInterest $first */
                $first = $group->first();

                return [
                    'what' => $first->what(),
                    'kind' => $first->product_id !== null ? 'product' : ($first->category_id !== null ? 'category' : 'brand'),
                    'sku' => $first->product?->sku,
                    // ⚠⚠ The number that matters: how many PEOPLE, not how many
                    // times somebody mentioned it.
                    'people' => $group->unique(fn (CrmProductInterest $i) => $i->subject_type.'#'.$i->subject_id)->count(),
                    'requests' => $group->count(),
                    // ⚠ Loud on purpose. A request for something we no longer
                    // sell is the whole point of the report.
                    'not_currently_sold' => $first->product_id !== null && ! $first->product?->is_active,
                ];
            })
            ->sortByDesc('people')
            ->values()
            ->all();
    }

    /**
     * Is anybody drowning.
     *
     * ⚠ C-CRMR-7. This shows EVERYBODY's workload, so it follows the same rule
     * as the queue screen: without `crm.tasks.view.all` a person sees only
     * their own, or an agent could read the whole team's load from the report
     * instead of the screen that guards it.
     */
    private function followUpLoad(Request $request): array
    {
        $user = $request->user();
        $seesEverybody = $user?->can('crm.tasks.view.all') ?? false;

        return CrmTask::query()
            ->when(! $seesEverybody, fn ($q) => $q->where('assigned_to_user_id', $user->id))
            ->where('status', TaskStatus::Open)
            ->with('assignedTo:id,name')
            ->get()
            ->groupBy('assigned_to_user_id')
            ->map(fn ($group) => [
                'who' => $group->first()->assignedTo?->name ?? 'Unassigned',
                'open' => $group->count(),
                // ⚠ Overdue is asked of OPEN tasks only; a task completed late
                // is done, not overdue.
                'overdue' => $group->filter(fn (CrmTask $t) => $t->isOverdue())->count(),
            ])
            ->sortByDesc('overdue')
            ->values()
            ->all();
    }
}
