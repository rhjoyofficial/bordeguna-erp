<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\FinalizeAttendance;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The processed attendance grid — what the engine made of each day, and the
 * POL-06 review step that turns a proposal into a payroll-eligible fact.
 *
 * Every row can explain itself: the engine's own reasoning is stored with the
 * result and shown on demand, so an employee asking "why am I marked half day"
 * gets an answer rather than an assertion.
 */
class DailyAttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.attendance.manage');

        $on = $request->date('on') ? Carbon::parse($request->string('on')->toString()) : Carbon::today();

        $days = DailyAttendance::with(['employee:id,employee_code,first_name,last_name'])
            ->onDate($on)
            ->when($request->boolean('review'), fn ($query) => $query->needingReview())
            ->get()
            ->sortBy(fn (DailyAttendance $day) => $day->employee?->fullName())
            ->values();

        // ⚠ A department may carry its own order, so "the policy in force" is no
        // longer one row. Naming only the business default here would put a
        // policy name above a grid half of whose rows were judged by another.
        $policies = AttendancePolicy::with('department')
            ->activeOn($on)
            ->orderByRaw('department_id is null')
            ->orderByDesc('version')
            ->get();

        $policy = $policies->firstWhere('department_id', null) ?? $policies->first();

        return Inertia::render('hrm/attendance/daily', [
            'on' => $on->toDateString(),
            'filters' => ['review' => $request->boolean('review')],
            'days' => $days->map(fn (DailyAttendance $day) => $this->present($day)),
            'summary' => [
                'total' => $days->count(),
                'provisional' => $days->where('is_provisional', true)->count(),
                'holds' => $days->where('holds_payroll', true)->count(),
                'with_consequence' => $days->filter(fn (DailyAttendance $d) => $d->consequence->deducts() || $d->consequence->warns())->count(),
            ],
            'policy' => $policy === null ? null : [
                'name' => $policy->name,
                'version' => $policy->version,
                'effective_from' => Carbon::parse($policy->effective_from)->toDateString(),
            ],
            'department_policies' => $policies->whereNotNull('department_id')->values()
                ->map(fn (AttendancePolicy $p) => [
                    'name' => $p->name,
                    'version' => $p->version,
                    'department' => $p->department?->name,
                ]),
        ]);
    }

    /**
     * Recalculate the day. Derived attendance is recomputable by design — the
     * punches underneath it never change (ATT-03).
     */
    public function process(Request $request, ProcessAttendance $action): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'on' => ['required', 'date'],
            'force' => ['nullable', 'boolean'],
        ]);

        $results = $action->forDate($validated['on'], (bool) ($validated['force'] ?? false));

        return back()->with('success', sprintf(
            '%d day%s processed.%s',
            $results->count(),
            $results->count() === 1 ? '' : 's',
            ($validated['force'] ?? false) ? '' : ' Finalised days were left untouched.',
        ));
    }

    /**
     * POL-06's human step. A day holding payroll for a missing punch is not
     * finalisable here — that needs an approved correction (Phase 2c).
     */
    public function finalize(Request $request, FinalizeAttendance $action): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'on' => ['required', 'date'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        $days = DailyAttendance::onDate($validated['on'])
            ->when($validated['ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->where('is_provisional', true)
            ->get();

        $result = $action->handle($days);

        return back()->with($result['finalized'] > 0 ? 'success' : 'error', sprintf(
            '%d day%s finalised.%s',
            $result['finalized'],
            $result['finalized'] === 1 ? '' : 's',
            $result['blocked'] > 0
                ? sprintf(' %d held for a missing punch — needs an approved correction first.', $result['blocked'])
                : '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DailyAttendance $day): array
    {
        return [
            'id' => $day->id,
            'employee' => $day->employee?->fullName(),
            'employee_code' => $day->employee?->employee_code,
            'status' => $day->status->label(),
            'status_value' => $day->status->value,
            'consequence' => $day->consequence->label(),
            'has_consequence' => $day->consequence->deducts() || $day->consequence->warns(),
            'first_in' => $day->first_in?->format('H:i:s'),
            'last_out' => $day->last_out?->format('H:i:s'),
            'worked_hours' => round($day->worked_minutes / 60, 2),
            'payable_hours' => round($day->payable_minutes / 60, 2),
            'late_minutes' => $day->late_minutes,
            'early_leave_minutes' => $day->early_leave_minutes,
            'grace_window' => $day->grace_window_key,
            'occurrence_number' => $day->occurrence_number,
            'is_provisional' => $day->is_provisional,
            'holds_payroll' => $day->holds_payroll,
            'flags' => $day->flags ?? [],
            // The engine's own derivation — this is what "explain every number"
            // means in practice.
            'notes' => $day->calculation['notes'] ?? [],
            'policy_version' => $day->policy_version,
            'finalized_by' => $day->finalizer?->name,
        ];
    }
}
