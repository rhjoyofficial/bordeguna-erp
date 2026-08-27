<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\RecordHolidayWork;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRM > Attendance > Holiday work. Who came in on a day nobody had to.
 *
 * ⚠⚠ THE SCREEN SHOWS WHO ACTUALLY PUNCHED, not an empty list to tick from
 * memory. Joy's ask was for holiday work to be assignable before or after the
 * day, and after the day the system already knows the answer: it has their
 * punches. Making him remember names when the database can tell him is how
 * people get missed.
 *
 * ⚠ Nothing here decides pay. An approved holiday day counts as a normal
 * present day, exactly as the engine already treated it.
 */
class HolidayWorkController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.leave.manage');

        $date = $request->query('date');
        $date = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();

        $businessId = app(BusinessContext::class)->id();

        // The day as the engine already recorded it, per person: this is what
        // says "holiday", and what carries the punches that prove attendance.
        $days = DailyAttendance::query()
            ->with('employee:id,employee_code,first_name,last_name')
            ->whereDate('attendance_date', $date)
            ->where('business_id', $businessId)
            ->get();

        $credited = Movement::query()
            ->where('type', MovementType::HolidayWork)
            ->whereDate('movement_date', $date)
            ->pluck('employee_id')
            ->all();

        $rows = $days->map(fn (DailyAttendance $day) => [
            'employee_id' => $day->employee_id,
            'employee_code' => $day->employee?->employee_code,
            'name' => $day->employee?->fullName(),
            'status' => $day->status?->value,
            'first_in' => $day->first_in?->format('H:i'),
            'last_out' => $day->last_out?->format('H:i'),
            'worked_minutes' => $day->worked_minutes,
            'payable_minutes' => $day->payable_minutes,
            // ⚠ The two facts that decide whether a name should be ticked: did
            // they punch at all, and have they already been credited.
            'has_punch' => $day->first_in !== null,
            'already_credited' => in_array($day->employee_id, $credited, true),
            'needs_review' => in_array('needs_review', (array) ($day->flags ?? []), true),
        ])->sortByDesc('has_punch')->values();

        return Inertia::render('hrm/attendance/holiday-work', [
            'date' => $date,
            // ⚠⚠ A non-working day is the only kind this applies to. Said as a
            // fact on the screen rather than enforced as a refusal, because the
            // holiday calendar can be filled in after the fact and the honest
            // answer then is "this is not a holiday yet", not silence.
            'is_non_working_day' => $days->contains(
                fn (DailyAttendance $day) => in_array($day->status?->value, ['holiday', 'weekly_off'], true),
            ),
            'rows' => $rows,
        ]);
    }

    public function store(Request $request, RecordHolidayWork $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $result = $action->handle(
            $data['employee_ids'],
            Carbon::parse($data['date'])->toDateString(),
            $data['reason'],
            $request->user(),
        );

        $parts = [];

        if ($result['approved'] > 0) {
            $parts[] = "{$result['approved']} approved and counted";
        }

        if ($result['pending'] > 0) {
            $parts[] = "{$result['pending']} waiting for another approver";
        }

        if ($result['skipped'] > 0) {
            $parts[] = "{$result['skipped']} already recorded";
        }

        return back()->with('success', 'Holiday work: ' . implode(', ', $parts) . '.');
    }

    /**
     * A year of holiday work, per person.
     *
     * ⚠ Counts APPROVED movements, not punches on holidays. An unapproved
     * holiday punch is not holiday work; it is a question nobody has answered,
     * and counting it here would quietly turn it into a credit.
     */
    public function report(Request $request): Response
    {
        $this->authorize('hrm.leave.manage');

        $year = (int) ($request->query('year') ?: Carbon::today()->year);
        $businessId = app(BusinessContext::class)->id();

        $rows = Movement::query()
            ->select('employee_id', DB::raw('COUNT(*) as days'))
            ->with('employee:id,employee_code,first_name,last_name')
            ->where('business_id', $businessId)
            ->where('type', MovementType::HolidayWork)
            ->whereYear('movement_date', $year)
            ->whereNotNull('applied_at')
            ->groupBy('employee_id')
            ->get()
            ->map(fn (Movement $row) => [
                'employee_code' => $row->employee?->employee_code,
                'name' => $row->employee?->fullName(),
                'days' => (int) $row->days,
            ])
            ->sortByDesc('days')
            ->values();

        $dates = Movement::query()
            ->where('business_id', $businessId)
            ->where('type', MovementType::HolidayWork)
            ->whereYear('movement_date', $year)
            ->whereNotNull('applied_at')
            ->orderBy('movement_date')
            ->get(['movement_date', 'employee_id'])
            ->groupBy(fn (Movement $m) => $m->movement_date->toDateString())
            ->map(fn ($group, $date) => ['date' => $date, 'people' => $group->count()])
            ->values();

        return Inertia::render('hrm/attendance/holiday-work-report', [
            'year' => $year,
            'rows' => $rows,
            'dates' => $dates,
            'total' => $rows->sum('days'),
        ]);
    }
}
