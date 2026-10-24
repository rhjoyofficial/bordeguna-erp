<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Enums\CampaignStatus;
use App\Modules\Crm\Enums\LeadStatus;
use App\Modules\Crm\Enums\LeadType;
use App\Modules\Crm\Enums\TaskStatus;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactGroup;
use App\Modules\Crm\Models\CrmTask;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Crm\Models\MessageOptOut;
use App\Modules\Crm\Models\SupplierLeadSample;
use App\Modules\Crm\Support\CrmReportQueries;
use App\Modules\Crm\Support\GoingCold;
use App\Modules\Crm\Support\LeadVisibility;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The CRM Overview (redesign round 3, 16 Oct 2026): one page, two views.
 *
 * ⭐ The TEAM view (Owner, Manager, a Sales lead with a team): where leads
 * stand, what needs somebody, follow-ups by person, new leads, districts,
 * leads going cold, sources, what people asked for, open deals and the phone
 * book. The PERSONAL view (a Sales officer, a call-centre agent): my day, my
 * follow-ups for today, my leads going cold, by status and by area.
 *
 * ⚠⚠ NO NEW VISIBILITY RULE. Every lead query passes the Lead model's global
 * scope (LeadVisibilityScope), every task query LeadVisibility::restrictTasks
 * and every deal LeadVisibility::restrict, so an officer's numbers are his own
 * and his team's on this page exactly as on the lists behind them. The blocks
 * that are not about leads (verification, samples, approvals, the phone book)
 * show by PERMISSION, never by role name.
 *
 * ⚠ No ranking of people (decision 5, 16 Oct): per-person counts are listed
 * alphabetically, in neutral words.
 *
 * Nothing is cached or stored: every figure is asked of the records on read.
 */
class CrmOverviewController extends Controller
{
    public function index(Request $request, CrmReportQueries $reports): Response
    {
        /** @var User $user */
        $user = $request->user();
        $businessId = (int) app(BusinessContext::class)->id();
        $visibility = app(LeadVisibility::class);

        // The team view for whoever reads more than their own work.
        $team = $visibility->seesEverything($user)
            || $user->can('crm.tasks.view.all')
            || count($visibility->userIdsFor($user)) > 1;

        $can = [
            'manage' => $user->can('crm.leads.manage'),
            'verify' => $user->can('crm.leads.verify'),
            'judge' => $user->can('crm.samples.judge'),
            'approve' => $user->can('crm.campaigns.approve'),
            'contacts' => $user->can('crm.contacts.view'),
            'send' => $user->can('crm.campaigns.send'),
        ];

        $now = Carbon::now();

        return Inertia::render('crm/overview', [
            'today' => $now->toDateString(),
            'viewer' => $user->name,
            'view' => $team ? 'team' : 'personal',
            'can' => $can,
            'team' => $team ? $this->teamView($user, $businessId, $can, $reports, $now) : null,
            'personal' => $team ? null : $this->personalView($user, $now),
        ]);
    }

    /**
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    private function teamView(User $user, int $businessId, array $can, CrmReportQueries $reports, Carbon $now): array
    {
        $tasks = $this->tasks($user);

        return [
            'stand' => $this->whereLeadsStand($now),
            'needs' => $this->needsYou($user, $businessId, $can, $now),
            'follow_ups' => [
                'due_today' => $tasks()->whereBetween('due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
                'overdue' => $tasks()->where('due_at', '<', $now)->count(),
                'done_this_week' => CrmTask::query()
                    ->tap(fn ($query) => app(LeadVisibility::class)->restrictTasks($query, $user))
                    ->where('status', TaskStatus::Done)
                    ->where('completed_at', '>=', $now->copy()->startOfWeek())
                    ->count(),
                // ⚠ Alphabetical, nobody first because he has the most (decision 5).
                'people' => $this->alphabetical($reports->followUpLoad($user)),
            ],
            'new_leads' => $this->newLeads($now),
            'districts' => $this->districts(),
            'going_cold' => $this->goingCold($now, 4),
            'sources' => array_slice($reports->sourcePerformance(), 0, 5),
            'demand' => array_slice($reports->unmetDemand(), 0, 4),
            'deals' => $this->openDeals($user, $businessId),
            'phone_book' => $can['contacts'] || $can['send'] ? $this->phoneBook($can) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personalView(User $user, Carbon $now): array
    {
        $tasks = $this->tasks($user);
        $endOfToday = $now->copy()->endOfDay();
        $oldestOverdue = $tasks()->where('due_at', '<', $now->copy()->startOfDay())->min('due_at');

        $today = $tasks()
            ->where('due_at', '<=', $endOfToday)
            ->with('subject')
            ->orderBy('due_at')
            ->limit(12)
            ->get()
            ->map(function (CrmTask $task) use ($now) {
                $subject = $task->subject;
                $isLead = $subject instanceof Lead;

                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'due_at' => $task->due_at?->toDateTimeString(),
                    'overdue' => $task->due_at !== null && $task->due_at->isBefore($now->copy()->startOfDay()),
                    'subject' => $subject === null ? null : [
                        'kind' => $isLead ? 'lead' : 'customer',
                        'id' => $subject->getKey(),
                        'name' => $subject->name,
                        'phone' => $subject->phone,
                        'place' => $isLead ? $subject->fullAddress() : null,
                    ],
                ];
            })
            ->values()
            ->all();

        $weekStart = $now->copy()->startOfWeek();
        [$converted, $lost] = $this->closedThisMonth($now);

        return [
            'my_day' => [
                'leads' => Lead::query()->workable()->count(),
                'due_today' => $tasks()->where('due_at', '<=', $endOfToday)->count(),
                'overdue' => $tasks()->where('due_at', '<', $now->copy()->startOfDay())->count(),
                'oldest_overdue' => $oldestOverdue === null ? null : Carbon::parse($oldestOverdue)->toDateString(),
                'new_this_week' => Lead::query()->workable()->where('created_at', '>=', $weekStart)->count(),
                'new_uncalled' => $this->neverContacted(Lead::query()->workable()->where('created_at', '>=', $weekStart))->count(),
                'going_cold' => GoingCold::apply(Lead::query(), $now)->count(),
                'converted' => $converted,
                'closed' => $converted + $lost,
            ],
            'today' => $today,
            'going_cold' => $this->goingCold($now, 5),
            'by_status' => $this->statusCounts([LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Opportunity, LeadStatus::Nurture]),
            'by_area' => Lead::query()->workable()
                ->whereNotNull('thana')
                ->selectRaw('thana, district, COUNT(*) as total')
                ->groupBy('thana', 'district')
                ->orderByDesc('total')
                ->orderBy('thana')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['thana' => $row->thana, 'district' => $row->district, 'count' => (int) $row->total])
                ->all(),
        ];
    }

    /**
     * Open follow-ups this person may see, as a fresh query each call.
     *
     * @return callable(): Builder<CrmTask>
     */
    private function tasks(User $user): callable
    {
        return fn () => CrmTask::query()
            ->tap(fn ($query) => app(LeadVisibility::class)->restrictTasks($query, $user))
            ->where('status', TaskStatus::Open);
    }

    /**
     * @param  list<LeadStatus>  $statuses
     * @return list<array{status: string, label: string, count: int}>
     */
    private function statusCounts(array $statuses): array
    {
        $counts = Lead::query()->workable()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return array_map(fn (LeadStatus $status) => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], $statuses);
    }

    /**
     * Converted and lost this calendar month, duplicates left out.
     *
     * @return array{0: int, 1: int}
     */
    private function closedThisMonth(Carbon $now): array
    {
        $month = [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];

        return [
            Lead::query()->whereNull('duplicate_of_lead_id')->where('status', LeadStatus::Converted)->whereBetween('converted_at', $month)->count(),
            Lead::query()->whereNull('duplicate_of_lead_id')->where('status', LeadStatus::Lost)->whereBetween('lost_at', $month)->count(),
        ];
    }

    /**
     * Leads with nothing recorded on them at all.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function neverContacted(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($activities) => $activities->selectRaw('1')->from('crm_activities')
            ->where('crm_activities.subject_type', (new Lead)->getMorphClass())
            ->whereColumn('crm_activities.subject_id', 'leads.id')
            // ⭐ Wave 3 (24 Oct 2026): a binned entry was never a contact.
            ->whereNull('crm_activities.deleted_at'));
    }

    /**
     * @return array<string, mixed>
     */
    private function whereLeadsStand(Carbon $now): array
    {
        [$converted, $lost] = $this->closedThisMonth($now);

        $lostReason = Lead::query()
            ->whereNull('duplicate_of_lead_id')
            ->where('status', LeadStatus::Lost)
            ->whereBetween('lost_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->whereNotNull('lost_reason_id')
            ->join('reasons', 'reasons.id', '=', 'leads.lost_reason_id')
            ->selectRaw('reasons.name as reason, COUNT(*) as total')
            ->groupBy('reasons.name')
            ->orderByDesc('total')
            ->orderBy('reasons.name')
            ->first();

        return [
            'stages' => $this->statusCounts([LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::Opportunity, LeadStatus::Nurture]),
            'new_uncalled' => $this->neverContacted(Lead::query()->workable()->where('status', LeadStatus::New))->count(),
            'converted_month' => $converted,
            'lost_month' => $lost,
            'lost_top_reason' => $lostReason === null ? null : ['reason' => $lostReason->reason, 'count' => (int) $lostReason->total],
            'conversion_rate' => $converted + $lost === 0 ? null : (int) round($converted / ($converted + $lost) * 100),
        ];
    }

    /**
     * The things waiting on somebody, each shown only to who may act on it.
     *
     * @param  array<string, bool>  $can
     * @return list<array{key: string, title: string, hint: string, count: int, tone: string, url: string}>
     */
    private function needsYou(User $user, int $businessId, array $can, Carbon $now): array
    {
        $needs = [];

        $overdue = $this->tasks($user)()->where('due_at', '<', $now)->with('assignedTo:id,name')->get();

        if ($overdue->isNotEmpty()) {
            $byPerson = $overdue->groupBy(fn (CrmTask $task) => $task->assignedTo?->name ?? 'unassigned')
                ->map(fn ($group, $who) => ['who' => $who, 'count' => $group->count()])
                ->sortBy(fn ($row) => [$row['who'] === 'unassigned' ? 1 : 0, mb_strtolower($row['who'])])
                ->map(fn ($row) => $row['who'].' '.$row['count'])
                ->values();

            $needs[] = [
                'key' => 'follow_ups_overdue',
                'title' => $overdue->count() === 1 ? 'Follow-up overdue' : 'Follow-ups overdue',
                'hint' => $byPerson->take(4)->implode(', ').($byPerson->count() > 4 ? ', and others' : ''),
                'count' => $overdue->count(),
                'tone' => 'danger',
                'url' => '/crm/queue',
            ];
        }

        if ($can['verify']) {
            $shops = Lead::query()->workable()
                ->whereNull('verified_at')
                ->whereIn('lead_type', [LeadType::Retailer->value, LeadType::Distributor->value, LeadType::Supplier->value]);
            $count = (clone $shops)->count();

            if ($count > 0) {
                $oldest = (clone $shops)->orderBy('created_at')->orderBy('id')->first();

                $needs[] = [
                    'key' => 'shops_unverified',
                    'title' => $count === 1 ? 'Shop waiting to be verified' : 'Shops waiting to be verified',
                    'hint' => 'Oldest: '.collect([$oldest?->name, $oldest?->thana ?? $oldest?->district])->filter()->implode(', ')
                        .($oldest?->created_at ? ', added '.$oldest->created_at->format('j M') : ''),
                    'count' => $count,
                    'tone' => 'warning',
                    'url' => '/crm/leads?verification=unverified',
                ];
            }
        }

        $tickets = DB::table('crm_tickets')
            ->where('business_id', $businessId)
            ->whereIn('status', ['open', 'in_progress'])
            ->where('resolve_by', '<', $now)
            ->orderBy('resolve_by')
            ->get(['subject', 'priority']);

        if ($tickets->isNotEmpty()) {
            $needs[] = [
                'key' => 'tickets_late',
                'title' => $tickets->count() === 1 ? 'Ticket past its time' : 'Tickets past their time',
                'hint' => $tickets->take(2)->map(fn ($ticket) => "{$ticket->subject} ({$ticket->priority})")->implode('; '),
                'count' => $tickets->count(),
                'tone' => 'danger',
                'url' => '/crm/tickets',
            ];
        }

        if ($can['judge']) {
            $samples = SupplierLeadSample::query()->whereNull('result')->with('lead:id,name')->orderBy('received_on')->get();

            if ($samples->isNotEmpty()) {
                $first = $samples->first();

                $needs[] = [
                    'key' => 'samples_waiting',
                    'title' => $samples->count() === 1 ? 'Supplier sample waiting for your result' : 'Supplier samples waiting for your result',
                    'hint' => collect([$first->lead?->name, $first->item])->filter()->implode(', ').', came '.$first->received_on?->format('j M'),
                    'count' => $samples->count(),
                    'tone' => 'warning',
                    'url' => $first->lead_id ? "/crm/lead/{$first->lead_id}/timeline" : '/crm/leads?lead_type=supplier',
                ];
            }
        }

        if ($can['approve']) {
            $sends = MessageCampaign::query()->where('status', CampaignStatus::PendingApproval)->orderBy('id')->get();

            if ($sends->isNotEmpty()) {
                $first = $sends->first();

                $needs[] = [
                    'key' => 'sends_waiting',
                    'title' => $sends->count() === 1 ? 'Send waiting for approval' : 'Sends waiting for approval',
                    'hint' => '"'.$first->name.'", '.number_format((int) $first->estimated_recipients).' numbers, about Tk '.number_format((float) $first->estimated_cost),
                    'count' => $sends->count(),
                    'tone' => 'warning',
                    'url' => '/crm/messages/'.$first->channel,
                ];
            }
        }

        return $needs;
    }

    /**
     * @return array<string, mixed>
     */
    private function newLeads(Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();
        $thisMonth = Lead::query()->workable()->where('created_at', '>=', $monthStart);
        $byType = (clone $thisMonth)->selectRaw('lead_type, COUNT(*) as total')->groupBy('lead_type')->pluck('total', 'lead_type');

        return [
            'month' => (clone $thisMonth)->count(),
            'last_month' => Lead::query()->workable()
                ->whereBetween('created_at', [$monthStart->copy()->subMonthNoOverflow(), $monthStart->copy()->subSecond()])
                ->count(),
            'by_type' => collect(LeadType::cases())->map(fn (LeadType $type) => [
                'type' => $type->value,
                'label' => $type->label(),
                'count' => (int) ($byType[$type->value] ?? 0),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function districts(): array
    {
        $rows = Lead::query()->workable()
            ->selectRaw("COALESCE(district, '') as place, COUNT(*) as total")
            ->groupBy('place')
            ->orderByDesc('total')
            ->orderBy('place')
            ->get();

        $top = $rows->take(6);
        $rest = $rows->slice(6);

        return [
            'working' => (int) $rows->sum('total'),
            'top' => $top->map(fn ($row) => ['district' => $row->place === '' ? null : $row->place, 'count' => (int) $row->total])->values()->all(),
            'other_districts' => $rest->count(),
            'other_count' => (int) $rest->sum('total'),
        ];
    }

    /**
     * @return array{count: int, rows: list<array<string, mixed>>}
     */
    private function goingCold(Carbon $now, int $limit): array
    {
        $morph = (new Lead)->getMorphClass();

        $rows = GoingCold::apply(Lead::query(), $now)
            ->select('leads.*')
            ->selectRaw(GoingCold::lastTouchSql().' as last_touch_at', [$morph])
            ->with('assignedTo:id,name')
            ->orderBy('last_touch_at')
            ->orderBy('leads.id')
            ->limit($limit)
            ->get();

        $lastTypes = DB::table('crm_activities')
            ->whereNull('deleted_at')
            ->whereNull('corrected_at')
            ->where('subject_type', $morph)
            ->whereIn('subject_id', $rows->pluck('id'))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['subject_id', 'type'])
            ->keyBy('subject_id');

        return [
            'count' => GoingCold::apply(Lead::query(), $now)->count(),
            'rows' => $rows->map(function (Lead $lead) use ($now, $lastTypes) {
                $lastType = ActivityType::tryFrom((string) ($lastTypes[$lead->id]->type ?? ''));

                return [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'type' => $lead->lead_type->label(),
                    'place' => collect([$lead->market, $lead->thana, $lead->district])->filter()->implode(', '),
                    'held_by' => $lead->assignedTo?->name,
                    'last' => $lastType?->label(),
                    'days' => (int) floor(Carbon::parse($lead->getAttribute('last_touch_at'))->diffInDays($now, true)),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Open deals this person may see, by stage. ⚠ The board's own rule
     * (OpportunityController::visibleDeals).
     *
     * @return array<string, mixed>
     */
    private function openDeals(User $user, int $businessId): array
    {
        $deals = DB::table('crm_opportunities')
            ->where('crm_opportunities.business_id', $businessId)
            ->where('crm_opportunities.status', 'open')
            ->tap(fn ($query) => app(LeadVisibility::class)->restrict($query, $user, 'crm_opportunities.assigned_to_user_id'))
            ->leftJoin('crm_pipeline_stages', 'crm_pipeline_stages.id', '=', 'crm_opportunities.stage_id')
            ->orderByDesc('crm_opportunities.value')
            ->get(['crm_opportunities.id', 'crm_opportunities.name', 'crm_opportunities.value', 'crm_opportunities.lead_id',
                'crm_opportunities.customer_id', 'crm_pipeline_stages.name as stage', 'crm_pipeline_stages.sort_order']);

        $top = $deals->first();
        $who = null;

        if ($top !== null) {
            $who = $top->lead_id !== null
                ? Lead::query()->whereKey($top->lead_id)->value('name')
                : Customer::query()->whereKey($top->customer_id)->value('name');
        }

        return [
            'count' => $deals->count(),
            'value' => (float) $deals->sum('value'),
            'stages' => $deals->groupBy(fn ($deal) => $deal->stage ?? 'No stage')
                ->map(fn ($group, $stage) => [
                    'stage' => $stage,
                    'order' => (int) ($group->first()->sort_order ?? 999),
                    'count' => $group->count(),
                    'value' => (float) $group->sum('value'),
                ])
                ->sortBy('order')
                ->values()
                ->all(),
            'top' => $top === null ? null : [
                'name' => $top->name,
                'who' => $who,
                'stage' => $top->stage,
                'value' => (float) $top->value,
            ],
        ];
    }

    /**
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    private function phoneBook(array $can): array
    {
        return [
            'contacts' => $can['contacts'] ? Contact::query()->where('is_active', true)->count() : null,
            'opted_out' => $can['contacts'] ? MessageOptOut::query()->count() : null,
            'groups' => $can['contacts'] ? ContactGroup::query()->where('is_active', true)->count() : null,
            'sends' => $can['send']
                ? MessageCampaign::query()
                    ->whereNot('status', CampaignStatus::Draft)
                    ->withCount(['deliveries as sent_count' => fn ($query) => $query->where('status', 'sent')])
                    ->withSum('deliveries as cost', 'cost')
                    ->orderByDesc('id')
                    ->limit(2)
                    ->get()
                    ->map(fn (MessageCampaign $send) => [
                        'id' => $send->id,
                        'name' => $send->name,
                        'channel' => $send->channel,
                        'status' => $send->status->value,
                        'status_label' => $send->status->label(),
                        'sent_on' => $send->sent_at?->toDateString(),
                        'sent' => (int) $send->getAttribute('sent_count'),
                        'cost' => round((float) $send->getAttribute('cost'), 2),
                    ])
                    ->all()
                : null,
        ];
    }

    /**
     * People in alphabetical order, Unassigned last.
     *
     * @param  list<array{who: string, open: int, overdue: int}>  $rows
     * @return list<array{who: string, open: int, overdue: int}>
     */
    private function alphabetical(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$a['who'] === 'Unassigned' ? 1 : 0, mb_strtolower($a['who'])]
            <=> [$b['who'] === 'Unassigned' ? 1 : 0, mb_strtolower($b['who'])]);

        return $rows;
    }
}
