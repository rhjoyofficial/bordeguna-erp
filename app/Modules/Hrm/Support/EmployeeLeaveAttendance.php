<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Actions\DecideLeavePay;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeavePayDecision;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollAdjustment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ THE EMPLOYEE'S LEAVE AND ATTENDANCE TAB (27 Oct 2026).
 *
 * Joy: "from where hr can see an employee's all the things, like leave, how
 * much left, paid, including everything hr should access for individual
 * employees". The employee page had details and money, but no leave, no
 * attendance and no pay changes. This gathers, for one person:
 *
 *   balances    per active balance type for a leave year: got, used and left,
 *               read off the leave ledger the way LeaveBalance::summaryFor and
 *               LeaveRegister do (used nets taken, a cancel's give-back and a
 *               pay decision's), so all three screens show the same numbers
 *   requests    the latest leave requests: dates, type, days, paid and unpaid
 *               days (the request's own pay decision, what payroll reads), the
 *               status as HRM, Leave shows it, and the latest pay decision
 *   attendance  the last six 24-23 payroll cycles (PayrollCycle, never the
 *               calendar month): present, late, half day, absent, on leave and
 *               missing punch days, provisional days, and worked days made
 *               unpaid for a rule break (MakeDayUnpaid) that still stand
 *   pay         every payroll adjustment on his pay (addition, deduction,
 *               penalty, recovery and the rest) and where each stands
 *
 * ⚠ Permissions: the page itself needs `hrm.employees.manage`. Leave needs
 * `hrm.leave.manage`, attendance `hrm.attendance.manage`, pay changes
 * `hrm.payroll.manage`. A part the viewer may not read is null, never an empty
 * list that looks like "never took leave".
 *
 * ⚠ Fixed query count, whatever the number of requests, days or adjustments.
 */
final class EmployeeLeaveAttendance
{
    /** How many requests and adjustments the tab lists, newest first. */
    private const LATEST = 50;

    /** How many payroll cycles the attendance part covers, this one included. */
    private const CYCLES = 6;

    public function __construct(
        private readonly Employee $employee,
        private readonly ?User $viewer,
        private readonly int $leaveYear,
    ) {}

    /** The leave year asked for (`?leave_year=`), this year when none or nonsense. */
    public static function yearFrom(?int $asked): int
    {
        return $asked !== null && $asked >= 2000 && $asked <= 2100 ? $asked : (int) Carbon::today()->year;
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return [
            'leave_year' => $this->leaveYear,
            'balances' => $this->balances(),
            'requests' => $this->requests(),
            'attendance' => $this->attendance(),
            'pay_changes' => $this->payChanges(),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, url: string|null}|null */
    public function balances(): ?array
    {
        if (! $this->sees('hrm.leave.manage')) {
            return null;
        }

        $types = LeaveType::active()->where('deducts_balance', true)->orderBy('name')->get(['id', 'name', 'code']);

        $ledger = LeaveLedgerEntry::where('employee_id', $this->employee->id)->forYear($this->leaveYear)
            // Used nets off a cancelled leave's give-back and a pay decision's.
            ->selectRaw("leave_type_id, sum(days) as total, sum(case when entry_type in ('taken', 'cancelled', 'pay_decision') then days else 0 end) as used")
            ->groupBy('leave_type_id')
            ->toBase()->get()
            ->keyBy('leave_type_id');

        return [
            'rows' => $types->map(function (LeaveType $type) use ($ledger) {
                $entry = $ledger->get($type->id);
                $all = (float) ($entry->total ?? 0);
                $used = abs((float) ($entry->used ?? 0));

                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'got' => round($all + $used, 2),
                    'used' => round($used, 2),
                    'left' => round($all, 2),
                ];
            })->values()->all(),
            'url' => $this->leaveUrl(),
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, url: string|null}|null */
    public function requests(): ?array
    {
        if (! $this->sees('hrm.leave.manage')) {
            return null;
        }

        $requests = LeaveRequest::with(['leaveType:id,name', 'approvalRequest', 'payDecisions.decider:id,name'])
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('start_date')->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();

        return [
            'rows' => $requests->map(function (LeaveRequest $request) {
                $days = (float) $request->days;
                $unpaid = (float) $request->unpaid_days;
                /** @var LeavePayDecision|null $decision */
                $decision = $request->payDecisions->last();

                return [
                    'id' => $request->id,
                    'start_date' => Carbon::parse($request->start_date)->toDateString(),
                    'end_date' => Carbon::parse($request->end_date)->toDateString(),
                    'leave_type' => $request->leaveType?->name,
                    'days' => $days,
                    'paid_days' => round($days - $unpaid, 2),
                    'unpaid_days' => round($unpaid, 2),
                    'status' => self::leaveStatus($request),
                    'pay_label' => DecideLeavePay::label($days, $unpaid),
                    'pay_decision' => $decision === null ? null : [
                        'reason' => $decision->reason,
                        'by' => $decision->decider?->name,
                        'at' => $decision->created_at?->format('d/m/Y'),
                    ],
                    'cancel_reason' => $request->cancel_reason,
                ];
            })->values()->all(),
            'url' => $this->leaveUrl(),
        ];
    }

    /**
     * The last six payroll cycles, newest first. A cycle that has not ended is
     * marked, since its days are still being punched.
     *
     * @return list<array<string, mixed>>|null
     */
    public function attendance(): ?array
    {
        if (! $this->sees('hrm.attendance.manage')) {
            return null;
        }

        $startDay = PayrollCycle::startDay();
        $today = Carbon::today();
        $current = PayrollCycle::containing($today, $startDay);

        $cycles = [];
        for ($back = 0; $back < self::CYCLES; $back++) {
            $label = Carbon::create($current['year'], $current['month'], 1)->subMonthsNoOverflow($back);
            [$start, $end] = PayrollCycle::forMonth((int) $label->year, (int) $label->month, $startDay);
            $cycles[] = [
                'year' => (int) $label->year,
                'month' => (int) $label->month,
                'start' => $start,
                'end' => $end,
                'counts' => array_fill_keys(['present', 'late', 'half_day', 'absent', 'on_leave', 'missing_punch', 'provisional'], 0),
                'made_unpaid' => 0.0,
            ];
        }

        $from = end($cycles)['start'];
        $to = $cycles[0]['end'];
        $find = function (Carbon $date) use (&$cycles): ?int {
            foreach ($cycles as $index => $cycle) {
                if ($date->betweenIncluded($cycle['start'], $cycle['end'])) {
                    return $index;
                }
            }

            return null;
        };

        $days = DailyAttendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->get(['attendance_date', 'status', 'is_provisional']);

        foreach ($days as $day) {
            $index = $find(Carbon::parse($day->attendance_date));
            if ($index === null) {
                continue;
            }

            $status = $day->status instanceof AttendanceStatus ? $day->status->value : (string) $day->status;
            if (array_key_exists($status, $cycles[$index]['counts']) && $status !== 'provisional') {
                $cycles[$index]['counts'][$status]++;
            }
            if ($day->is_provisional) {
                $cycles[$index]['counts']['provisional']++;
            }
        }

        // Only an approved, standing penalty is a day made unpaid; a waiting
        // one is not yet a decision (the same rule as LeaveRegister).
        $penalties = PayrollAdjustment::approved()
            ->where('employee_id', $this->employee->id)
            ->where('type', PayrollAdjustmentType::Penalty)
            ->whereNull('cancelled_at')
            ->where('source_key', 'like', 'day_unpaid:'.$this->employee->id.':%')
            ->where('source_key', 'not like', '%|closed:%')
            ->get(['id', 'source_key', 'reason']);

        foreach ($penalties as $penalty) {
            // day_unpaid:{employee}:{Y-m-d}
            $date = explode(':', (string) $penalty->source_key)[2] ?? null;
            $index = $date === null ? null : $find(Carbon::parse($date));
            if ($index !== null) {
                $cycles[$index]['made_unpaid'] += str_contains((string) $penalty->reason, 'half day unpaid') ? 0.5 : 1.0;
            }
        }

        $mayOpen = Route::has('hrm.attendance.employee.show');

        return array_map(fn (array $cycle) => [
            'year' => $cycle['year'],
            'month' => $cycle['month'],
            'label' => PayrollCycle::describe($cycle['year'], $cycle['month'], $startDay),
            'start' => $cycle['start']->toDateString(),
            'end' => $cycle['end']->toDateString(),
            'running' => $today->lte($cycle['end']),
            ...$cycle['counts'],
            'made_unpaid' => $cycle['made_unpaid'],
            'url' => $mayOpen ? route('hrm.attendance.employee.show', [
                'employee' => $this->employee->id,
                'from' => $cycle['start']->toDateString(),
                'to' => $cycle['end']->toDateString(),
            ], false) : null,
        ], $cycles);
    }

    /** @return array{rows: list<array<string, mixed>>, url: string|null}|null */
    public function payChanges(): ?array
    {
        if (! $this->sees('hrm.payroll.manage')) {
            return null;
        }

        $adjustments = PayrollAdjustment::with(['period', 'approvalRequest'])
            ->where('employee_id', $this->employee->id)
            ->orderByDesc('id')
            ->limit(self::LATEST)
            ->get();

        return [
            'rows' => $adjustments->map(function (PayrollAdjustment $adjustment) {
                $status = self::adjustmentStatus($adjustment);

                return [
                    'id' => $adjustment->id,
                    'period' => $adjustment->period?->label(),
                    'type' => $adjustment->type->value,
                    'type_label' => $adjustment->type->label(),
                    'is_earning' => $adjustment->type->isEarning(),
                    'amount' => Money::ofTaka((string) $adjustment->amount)->format(),
                    'reason' => $adjustment->reason,
                    'status' => $status,
                    'decided_on' => in_array($status, ['waiting', 'generated'], true)
                        ? null
                        : ($adjustment->cancelled_at ?? $adjustment->approvalRequest?->reviewed_at)?->format('d/m/Y'),
                    'cancel_reason' => $adjustment->cancel_reason,
                ];
            })->values()->all(),
            'url' => Route::has('hrm.payroll.inputs.index')
                ? route('hrm.payroll.inputs.index', ['employee_id' => $this->employee->id], false)
                : null,
        ];
    }

    /**
     * Pending, approved, rejected, withdrawn (sent back by the sender before a
     * decision) or cancelled (after approval), as HRM, Leave reads it.
     */
    private static function leaveStatus(LeaveRequest $request): string
    {
        $approval = $request->approvalRequest?->status;

        if ($request->isCancelled()) {
            return ! $request->isApplied() && $approval === ApprovalStatus::Returned ? 'withdrawn' : 'cancelled';
        }

        return match ($approval) {
            ApprovalStatus::Approved => 'approved',
            ApprovalStatus::Rejected => 'rejected',
            ApprovalStatus::Returned => 'withdrawn',
            default => 'pending',
        };
    }

    /**
     * Waiting, approved, rejected, withdrawn or cancelled. A row the system
     * made (an arrear, a claim put on salary) has no approval of its own and
     * reads "generated", as Payroll, Inputs shows it.
     */
    private static function adjustmentStatus(PayrollAdjustment $adjustment): string
    {
        $approval = $adjustment->approvalRequest?->status;

        if ($adjustment->isCancelled()) {
            return $approval === ApprovalStatus::Returned ? 'withdrawn' : 'cancelled';
        }

        if ($adjustment->approvalRequest === null) {
            return $adjustment->source_key !== null ? 'generated' : 'waiting';
        }

        return match ($approval) {
            ApprovalStatus::Approved => 'approved',
            ApprovalStatus::Rejected => 'rejected',
            ApprovalStatus::Returned => 'withdrawn',
            default => 'waiting',
        };
    }

    private function leaveUrl(): ?string
    {
        return Route::has('hrm.leave.index')
            ? route('hrm.leave.index', ['employee_id' => $this->employee->id, 'year' => $this->leaveYear], false)
            : null;
    }

    private function sees(string $ability): bool
    {
        return $this->viewer === null || $this->viewer->can($ability);
    }
}
