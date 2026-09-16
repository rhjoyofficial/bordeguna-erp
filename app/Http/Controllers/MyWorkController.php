<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Support\BusinessContext;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * MY WORK (Module 1, Slice 3; master plan §9): everything waiting on THIS
 * person, one page. Approvals they can act on, follow-ups assigned to them,
 * their own to-dos, the requests they are waiting on others for - and, for
 * the roles that hold them, the attendance rows needing review and the
 * standing costs falling due.
 *
 * ⚠⚠ Sections a role does not hold are ABSENT from the payload, not empty -
 * absence is what tells the page "not your job", exactly as the sidebar hides
 * the screens. And a login with no permissions at all still gets a working
 * page of its own items: My Work must never 403.
 *
 * ⚠ Approvals come from ApprovalInbox - the SAME source as the inbox screen
 * and the sidebar badge, so the three can never disagree about how much is
 * waiting. Low stock is deliberately not here yet: its number lives in a
 * screen-local computation, and a second copy that drifts from the screen
 * would be worse than a link; it joins when that computation is shared.
 */
class MyWorkController extends Controller
{
    private const PER_SECTION = 5;

    public function __invoke(Request $request, ApprovalInbox $inbox, ApprovalCards $cards): Response
    {
        $user = $request->user();

        $sections = [
            'approvals' => $this->approvals($user, $inbox, $cards),
            'followups' => $this->followups($user),
            'todos' => $this->todos($user),
            'my_requests' => $this->myRequests($user),
        ];

        if ($user->can('hrm.attendance.manage')) {
            $sections['attendance'] = $this->attendance();
        }

        if ($user->can('purchasing.expense.record')) {
            $sections['recurring'] = $this->recurring();
        }

        if ($user->can('assets.items.manage')) {
            $sections['assets'] = $this->assets();
        }

        return Inertia::render('my-work', $sections);
    }

    /** @return array{count: int, items: array<int, array<string, mixed>>} */
    private function approvals($user, ApprovalInbox $inbox, ApprovalCards $cards): array
    {
        $pending = $inbox->pendingActionableFor($user);

        return [
            'count' => $pending->count(),
            'items' => $pending->take(self::PER_SECTION)
                ->map(function (ApprovalRequest $request) use ($cards) {
                    $card = $cards->present($request);

                    return [
                        'type' => $card['type'],
                        'summary' => $card['summary'],
                        'requested_by' => $request->requestedBy?->name,
                        'since' => $request->created_at?->diffForHumans(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /** Follow-ups assigned to me, due today or overdue - the queue's edge. */
    private function followups($user): array
    {
        $due = DB::table('crm_tasks')
            ->where('assigned_to_user_id', $user->id)
            ->whereNull('completed_at')
            ->where('due_at', '<=', now()->endOfDay())
            ->orderBy('due_at');

        return [
            'count' => (clone $due)->count(),
            'items' => $due->limit(self::PER_SECTION)
                ->get(['id', 'title', 'due_at'])
                ->map(fn ($task) => [
                    'title' => $task->title,
                    'due' => Carbon::parse($task->due_at)->diffForHumans(),
                ])
                ->all(),
        ];
    }

    private function todos($user): array
    {
        $open = DB::table('personal_todos')
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on');

        return [
            'count' => (clone $open)->count(),
            'items' => $open->limit(self::PER_SECTION)
                ->get(['id', 'title', 'due_on'])
                ->map(fn ($todo) => ['title' => $todo->title, 'due_on' => $todo->due_on])
                ->all(),
        ];
    }

    /** What I asked for that somebody else has not decided yet. */
    private function myRequests($user): array
    {
        $waiting = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Pending)
            ->where('requested_by', $user->id)
            ->orderBy('created_at');

        return [
            'count' => (clone $waiting)->count(),
            'items' => $waiting->limit(self::PER_SECTION)
                ->get()
                ->map(fn (ApprovalRequest $request) => [
                    'type' => class_basename($request->approvable_type),
                    'since' => $request->created_at?->diffForHumans(),
                ])
                ->all(),
        ];
    }

    private function attendance(): array
    {
        $window = now()->subDays(35)->toDateString();

        return [
            'needs_review' => DB::table('daily_attendances')
                ->where('attendance_date', '>=', $window)
                ->where('flags', 'like', '%needs_review%')
                ->count(),
            'holds_payroll' => DB::table('daily_attendances')
                ->where('attendance_date', '>=', $window)
                ->where('holds_payroll', 1)
                ->count(),
        ];
    }

    /**
     * Asset hand-overs waiting on somebody (slice A2): transfers not yet
     * received, and things issued three days ago that nobody has acknowledged.
     *
     * @return array{in_transit: int, unacknowledged: int, open_gaps: int}
     */
    private function assets(): array
    {
        $businessId = app(BusinessContext::class)->id();

        return [
            'in_transit' => DB::table('asset_movements')->where('business_id', $businessId)->where('status', 'dispatched')->count(),
            'unacknowledged' => DB::table('asset_items')->where('business_id', $businessId)
                ->whereNotNull('custodian_employee_id')->whereNull('acknowledged_at')
                ->where('custody_since', '<=', now()->subDays(3)->toDateString())->count(),
            // Slice A3: stock-take gaps nobody has settled.
            'open_gaps' => DB::table('asset_discrepancies')->where('business_id', $businessId)->where('state', 'open')->count(),
        ];
    }

    private function recurring(): array
    {
        $due = DB::table('recurring_expense_occurrences')
            ->join('recurring_expenses', 'recurring_expenses.id', '=', 'recurring_expense_occurrences.recurring_expense_id')
            ->where('recurring_expense_occurrences.status', 'due')
            ->where('due_on', '<=', now()->addDays(7)->toDateString())
            ->orderBy('due_on');

        return [
            'count' => (clone $due)->count(),
            'items' => $due->limit(self::PER_SECTION)
                ->get(['recurring_expenses.name', 'due_on', 'expected_amount'])
                ->map(fn ($occurrence) => [
                    'label' => $occurrence->name,
                    'due_on' => $occurrence->due_on,
                    'amount' => $occurrence->expected_amount,
                ])
                ->all(),
        ];
    }
}
