<?php

namespace App\Modules\Hrm\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Support\FrozenAttendance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): what a leave or movement cancellation
 * does to pay.
 *
 * ⛔ Locked payroll is never changed. A day inside a cycle whose payroll is
 * approved (rule #18, FrozenAttendance) is NOT judged again; the difference is
 * priced and lands on the next open payroll as an arrear or a deduction, which
 * ProcessAttendance already does for every late change of evidence. That is
 * allowed, but it is the "HR plus one other approver" case (FINAL-PLAN 3.4),
 * so the policy routes it to a second person (`frozenDate`).
 *
 * ⚠ A day inside a LOCKED attendance period (C15e) cannot be judged again at
 * all, so that is a refusal: unlock the period first.
 */
class FrozenPayrollCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Payroll;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        foreach (self::datesOf($document) as $date) {
            if (AttendancePeriodLock::isLocked($date)) {
                return new Blocker('attendance_locked', 'Attendance for '.Carbon::parse($date)->format('d/m/Y').' is locked, so unlock that period first.');
            }
        }

        return null;
    }

    /** The first covered date whose payroll is already approved, or null. */
    public static function frozenDate(array $dates): ?string
    {
        foreach ($dates as $date) {
            if (FrozenAttendance::runCovering($date) !== null) {
                return Carbon::parse($date)->toDateString();
            }
        }

        return null;
    }

    /** @return list<string> the days the record changes */
    public static function datesOf(Model $document): array
    {
        return match (true) {
            $document instanceof LeaveRequest => array_values($document->covered_dates ?? []),
            $document instanceof Movement => [Carbon::parse($document->movement_date)->toDateString()],
            default => [],
        };
    }
}
