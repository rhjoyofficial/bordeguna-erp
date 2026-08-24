<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The raw punch log (ATT-01/03) — HR's window onto what was actually captured,
 * plus the controlled manual entry ATT-01 allows for the offline fallback in
 * ATT-07 (manual register, later keyed in by HR).
 *
 * There is deliberately no edit or delete action here: punches are immutable
 * (ATT-03) and the model refuses either. Manual entry requires a reason, which
 * is what makes the audit trail worth having.
 */
class AttendancePunchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.attendance.manage');

        // ⚠ This screen used to show ONE day and default it to today. Import a
        // fortnight of punches on a day the device recorded none, open the log,
        // and it says nothing was captured — which is exactly what happened on
        // 16 August after 136 punches landed cleanly. A log you cannot scroll
        // through is not a log. The range is the default view now; `on` still
        // works so an existing link keeps meaning what it meant.
        [$from, $to] = $this->range($request);

        $employeeId = $request->integer('employee_id') ?: null;
        $onlyReview = $request->boolean('review');
        $sort = in_array($request->string('sort')->toString(), ['time', 'employee'], true)
            ? $request->string('sort')->toString()
            : 'time';
        $direction = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = AttendancePunch::with(['employee:id,employee_code,first_name,last_name', 'recorder:id,name'])
            ->whereBetween('punched_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($onlyReview, fn ($q) => $q->needingReview());

        // Counted before paging, so the summary describes the whole range rather
        // than whichever slice happens to be on screen.
        $summary = [
            'total' => (clone $query)->count(),
            'needs_review' => (clone $query)->needingReview()->count(),
            'employees' => (clone $query)->distinct()->count('employee_id'),
            'days' => (clone $query)->distinct()->count(DB::raw('date(punched_at)')),
        ];

        $punches = $this->sorted($query, $sort, $direction)
            ->paginate(100)
            ->withQueryString()
            ->through(fn (AttendancePunch $punch) => $this->present($punch));

        return Inertia::render('hrm/attendance/index', [
            'on' => $to->toDateString(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employee_id' => $employeeId,
                'review' => $onlyReview,
                'sort' => $sort,
                'dir' => $direction,
            ],
            'punches' => $punches,
            'summary' => $summary,
            'employees' => $this->employeeOptions(),
            'directions' => collect(PunchDirection::cases())
                ->map(fn (PunchDirection $d) => ['value' => $d->value, 'label' => $d->label()]),
        ]);
    }

    /**
     * HR keys in a punch from the manual register (ATT-07). Recorded as a
     * normal immutable punch tagged Manual, with a mandatory reason so the
     * activity trail ATT-01 requires is meaningful.
     */
    public function store(Request $request, RecordPunch $recordPunch): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId()),
            ],
            'punched_at' => ['required', 'date'],
            'direction' => ['nullable', new EnumRule(PunchDirection::class)],
            // A manual punch without a stated reason is exactly the record an
            // auditor cannot evaluate later, so it is required rather than nice.
            'note' => ['required', 'string', 'max:255'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        $punch = $recordPunch->handle($employee, $validated['punched_at'], PunchSource::Manual, [
            'direction' => $validated['direction'] ?? null,
            'note' => $validated['note'],
        ]);

        return back()->with(
            $punch === null ? 'error' : 'success',
            $punch === null
                ? 'That punch is already recorded for this employee and time.'
                : 'Punch recorded.',
        );
    }

    /**
     * The window being looked at.
     *
     * A bare `on=` still means that single day, so old links and the "jump to a
     * day" control keep working. Otherwise the default is the last 30 days up to
     * the most recent punch there actually is — not up to today, because today
     * is frequently a day nobody has imported yet, and an empty screen reads as
     * "no data exists" rather than "you are looking past the end of it".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        if ($request->filled('on') && ! $request->filled('from') && ! $request->filled('to')) {
            $on = Carbon::parse($request->string('on')->toString());

            return [$on, $on];
        }

        $latest = AttendancePunch::max('punched_at');
        $anchor = $latest !== null ? Carbon::parse($latest) : Carbon::today();

        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $anchor;
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->subDays(29);

        // A backwards range returns nothing and looks like missing data, so it
        // is corrected rather than obeyed.
        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AttendancePunch>  $query
     * @return \Illuminate\Database\Eloquent\Builder<AttendancePunch>
     */
    private function sorted($query, string $sort, string $direction)
    {
        if ($sort === 'employee') {
            // Ordered by the person, then their day in time order, so one
            // employee's fortnight reads as a continuous story.
            return $query->join('employees', 'employees.id', '=', 'attendance_punches.employee_id')
                ->orderBy('employees.first_name', $direction)
                ->orderBy('employees.last_name', $direction)
                ->orderBy('attendance_punches.punched_at')
                ->select('attendance_punches.*');
        }

        return $query->orderBy('punched_at', $direction);
    }

    private function businessId(): int
    {
        return app(\App\Modules\Foundation\Support\BusinessContext::class)->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AttendancePunch $punch): array
    {
        return [
            'id' => $punch->id,
            'employee_id' => $punch->employee_id,
            'employee' => $punch->employee?->fullName(),
            'employee_code' => $punch->employee?->employee_code,
            'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
            // The log spans days now, so the date has to travel with the row.
            'date' => $punch->punched_at->format('Y-m-d'),
            'weekday' => $punch->punched_at->format('D'),
            'time' => $punch->punched_at->format('H:i:s'),
            'source' => $punch->source->label(),
            'direction' => $punch->direction?->label(),
            'device_identifier' => $punch->device_identifier,
            'enroll_id' => $punch->enroll_id,
            'has_location' => $punch->latitude !== null && $punch->longitude !== null,
            'location' => $punch->latitude !== null && $punch->longitude !== null
                ? $punch->latitude.', '.$punch->longitude
                : null,
            'gps_accuracy_meters' => $punch->gps_accuracy_meters,
            'ip_address' => $punch->ip_address,
            'is_outside_geofence' => $punch->is_outside_geofence,
            'needs_review' => $punch->needs_review,
            'review_reason' => $punch->review_reason,
            'note' => $punch->note,
            'recorded_by' => $punch->recorder?->name,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function employeeOptions()
    {
        return Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
            ->orderBy('employee_code')->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->fullName().' ('.$employee->employee_code.')',
            ]);
    }
}
