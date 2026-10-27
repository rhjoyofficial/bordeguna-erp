<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollAdjustment;
use Illuminate\Support\Collection;

/**
 * ⭐ Leave register (Joy, 27 Oct 2026: "from where hr see which each employees
 * leaves, how much they used, how much not, which leave was paid, which not").
 * Every employee on one line for a leave year:
 *
 *  - per balance type: what they got (grant, carry forward, credits), what they
 *    used, and what is left, all read off the leave ledger like LeaveBalance;
 *  - leave days paid and unpaid, from the applied, not cancelled requests that
 *    start in the year. `unpaid_days` is the request's own pay decision, the
 *    figure payroll's Leave Without Pay line reads, so the two always agree;
 *  - unauthorised absent days, from processed attendance;
 *  - worked days made unpaid for a rule break (MakeDayUnpaid), still standing.
 */
class LeaveRegister
{
    /** @return array{types: Collection<int, array<string, mixed>>, rows: Collection<int, array<string, mixed>>} */
    public static function for(int $year): array
    {
        $types = LeaveType::active()->where('deducts_balance', true)->orderBy('name')->get(['id', 'name']);

        $employees = Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
            ->orderBy('employee_code')->get();
        $ids = $employees->pluck('id');

        $ledger = LeaveLedgerEntry::whereIn('employee_id', $ids)->forYear($year)
            // Used nets off a cancelled leave's give-back and a pay decision's.
            ->selectRaw("employee_id, leave_type_id, sum(days) as total, sum(case when entry_type in ('taken', 'cancelled', 'pay_decision') then days else 0 end) as used")
            ->groupBy('employee_id', 'leave_type_id')
            ->toBase()->get()
            ->groupBy(fn ($e) => $e->employee_id.':'.$e->leave_type_id);

        $leaves = LeaveRequest::applied()->whereIn('employee_id', $ids)
            ->whereYear('start_date', $year)
            ->get(['employee_id', 'days', 'unpaid_days'])
            ->groupBy('employee_id');

        $absent = DailyAttendance::whereIn('employee_id', $ids)
            ->whereYear('attendance_date', $year)
            ->where('status', AttendanceStatus::Absent)
            ->selectRaw('employee_id, count(*) as days')
            ->groupBy('employee_id')
            ->pluck('days', 'employee_id');

        // Only approved penalties stand; a waiting one is not yet a decision.
        $madeUnpaid = PayrollAdjustment::approved()->whereIn('employee_id', $ids)
            ->where('type', PayrollAdjustmentType::Penalty)
            ->whereNull('cancelled_at')
            ->where('source_key', 'like', 'day_unpaid:%:'.$year.'-%')
            ->where('source_key', 'not like', '%|closed:%')
            ->get(['employee_id', 'reason'])
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($types, $ledger, $leaves, $absent, $madeUnpaid) {
            $own = $leaves->get($employee->id, collect());
            $unpaidLeave = round((float) $own->sum('unpaid_days'), 2);

            return [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'code' => $employee->employee_code,
                'types' => $types->mapWithKeys(function (LeaveType $type) use ($ledger, $employee) {
                    $entry = $ledger->get($employee->id.':'.$type->id)?->first();
                    $all = (float) ($entry->total ?? 0);
                    $used = abs((float) ($entry->used ?? 0));

                    return [$type->id => [
                        'got' => round($all + $used, 2),
                        'used' => round($used, 2),
                        'left' => round($all, 2),
                    ]];
                }),
                'paid_leave' => round((float) $own->sum('days') - $unpaidLeave, 2),
                'unpaid_leave' => $unpaidLeave,
                'absent' => (int) ($absent[$employee->id] ?? 0),
                'made_unpaid' => (float) $madeUnpaid->get($employee->id, collect())
                    ->sum(fn (PayrollAdjustment $a) => str_contains((string) $a->reason, 'half day unpaid') ? 0.5 : 1.0),
            ];
        })->values();

        return [
            'types' => $types->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'rows' => $rows,
        ];
    }
}
