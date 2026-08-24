<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\FinalizeAttendance;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Enums\CorrectionType;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
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

        // ⚠ Single-day only, defaulted to today, meant reviewing a fortnight took
        // fourteen clicks and an empty screen on any day nobody had imported.
        // The range is the working view; `on` still selects one day.
        [$from, $to] = $this->range($request);
        $on = $to;

        $employeeId = $request->integer('employee_id') ?: null;
        $onlyHolds = $request->boolean('holds');

        $days = DailyAttendance::with(['employee:id,employee_code,first_name,last_name'])
            // ⚠⚠ whereBetween on the raw strings looks right and silently returns
            // NOTHING: attendance_date is date-cast but stored with a time, so
            // "2026-08-03 00:00:00" is not between "2026-08-03" and "2026-08-03"
            // once the comparison falls back to strings. Sixth appearance of this
            // trap in this codebase. whereDate is the form that survives it.
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($request->boolean('review'), fn ($query) => $query->needingReview())
            ->when($onlyHolds, fn ($query) => $query->where('holds_payroll', true))
            // "Meal exceptions only": any day the meal resolver flagged. The
            // flags live in one JSON column, so a LIKE per flag beats seven
            // schema columns nobody else reads.
            ->when($request->boolean('meals'), fn ($query) => $query->where(
                fn ($q) => collect(DailyAttendance::MEAL_FLAGS)
                    ->each(fn (string $flag) => $q->orWhere('flags', 'like', '%"'.$flag.'"%')),
            ))
            ->get()
            // Date first, then person, so a range reads as a diary rather than a
            // heap. A single day is unchanged: one date, sorted by name.
            ->sortBy(fn (DailyAttendance $day) => $day->attendance_date->toDateString().'|'.$day->employee?->fullName())
            ->values();

        // Every applied fix in the window, so a corrected row can say what was
        // done and by whom - the trail Joy asked for ("the last all logs
        // together for this"). One query, grouped, rather than one per row.
        $fixes = AttendanceCorrection::with('requester:id,name')
            ->whereNotNull('applied_at')
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AttendanceCorrection $c) => $c->employee_id.'|'.Carbon::parse($c->attendance_date)->toDateString());

        // Active waivers in the window, so a forgiven row can say so and the
        // Forgive button knows not to offer itself twice. Same one-query,
        // grouped shape as the fixes above.
        $waivers = AttendanceWaiver::with('waiver:id,name')
            ->active()
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->get()
            ->keyBy(fn (AttendanceWaiver $w) => $w->employee_id.'|'.$w->attendance_date->toDateString());

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
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employee_id' => $employeeId,
                'review' => $request->boolean('review'),
                'holds' => $onlyHolds,
                'meals' => $request->boolean('meals'),
            ],
            'employees' => Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
                ->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName().' ('.$e->employee_code.')']),
            'days' => $days->map(fn (DailyAttendance $day) => $this->present($day, $fixes, $waivers)),
            // Forgiving money is its own permission, not part of managing
            // attendance - the button only shows to those who hold it.
            'can_waive' => $request->user()->can('hrm.attendance.waive'),
            // The fix dialog's vocabulary, same shapes the clock page uses.
            // Anyone on this screen holds hrm.attendance.manage, so a
            // correction filed here applies IMMEDIATELY and reprocesses the
            // day (RequestCorrection's privileged path) - which is what Joy
            // means by "can be fixed direct from here".
            'correction_types' => collect(CorrectionType::cases())
                ->map(fn (CorrectionType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'needs_punch' => $t->needsPunch(),
                    'needs_time' => $t->needsTime(),
                ]),
            'directions' => collect(PunchDirection::cases())
                ->map(fn (PunchDirection $d) => ['value' => $d->value, 'label' => $d->label()]),
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
     * The window being reviewed. `on` alone still means that one day, so the
     * existing links and the process/finalise buttons keep their meaning.
     *
     * The default anchors on the newest processed day rather than today, because
     * today is routinely a day nothing has been processed for yet and an empty
     * grid reads as "nothing here" instead of "you are past the end".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        if ($request->filled('on') && ! $request->filled('from') && ! $request->filled('to')) {
            $on = Carbon::parse($request->string('on')->toString());

            return [$on, $on];
        }

        $latest = DailyAttendance::max('attendance_date');
        $anchor = $latest !== null ? Carbon::parse($latest) : Carbon::today();

        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $anchor;
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->subDays(29);

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DailyAttendance $day, \Illuminate\Support\Collection $fixes, \Illuminate\Support\Collection $waivers): array
    {
        $key = $day->employee_id.'|'.$day->attendance_date->toDateString();
        $waiver = $waivers->get($key);

        return [
            // Forgiveness, if any: who removed this day's deduction and why.
            // The day's own verdict below stays untouched - that is the point.
            'waiver' => $waiver === null ? null : [
                'id' => $waiver->id,
                'reason' => $waiver->reason,
                'by' => $waiver->waiver?->name,
                'at' => $waiver->created_at?->format('Y-m-d H:i'),
            ],
            // Whether this day's verdict costs money (an absent day, or a
            // deducting consequence) - what the Forgive button keys on.
            'costs_money' => $day->status->value === 'absent' || $day->consequence->deducts(),
            // What has already been done to this day, and by whom - shown on
            // the row and, through the clock page, to the employee themselves.
            'fixes' => ($fixes->get($key) ?? collect())->map(fn (AttendanceCorrection $c) => [
                'summary' => $c->summaryLine(),
                'by' => $c->requester?->name,
                'at' => $c->applied_at?->format('Y-m-d H:i'),
            ])->values(),
            'id' => $day->id,
            'date' => $day->attendance_date->toDateString(),
            'weekday' => $day->attendance_date->format('D'),
            'employee_id' => $day->employee_id,
            'employee' => $day->employee?->fullName(),
            'employee_code' => $day->employee?->employee_code,
            'status' => $day->status->label(),
            'status_value' => $day->status->value,
            'consequence' => $day->consequence->label(),
            'has_consequence' => $day->consequence->deducts() || $day->consequence->warns(),
            'first_in' => $day->first_in?->format('H:i:s'),
            'last_out' => $day->last_out?->format('H:i:s'),
            'worked_hours' => round($day->worked_minutes / 60, 2),
            'break_minutes' => $day->break_minutes,
            'meal' => $day->calculation['meal'] ?? null,
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
