<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The early leave settings (Joy's HRM queue, 27 Oct 2026), read from
 * config/hrm.php `early_leave` and nowhere else, so each rule Joy left open is
 * one clearly named setting that can change later.
 *
 * ⚠ The approved case is not a setting: an approved early leave always carries
 * no deduction (POL-04) and is decided in ApplyGraceAndThresholds.
 */
class EarlyLeaveRules
{
    /**
     * Option A (recommended, applied): an early departure WITHOUT an approved
     * request keeps today's rule. Flagged for a human, and a half day when the
     * payable hours fall to the half-day line.
     */
    public const UNAPPROVED_HALF_DAY_RULE = 'half_day_rule';

    /** @var list<string> the values the engine implements */
    public const UNAPPROVED_OPTIONS = [self::UNAPPROVED_HALF_DAY_RULE];

    /**
     * ⚠ Refuses an unknown value rather than quietly treating it as option A:
     * a setting that changes nothing would read as a rule change nobody made.
     */
    public static function unapprovedDeparture(): string
    {
        $value = (string) config('hrm.early_leave.unapproved_departure', self::UNAPPROVED_HALF_DAY_RULE);

        if (! in_array($value, self::UNAPPROVED_OPTIONS, true)) {
            throw new InvalidArgumentException("Unknown early leave rule [{$value}]. The engine implements: ".implode(', ', self::UNAPPROVED_OPTIONS).'.');
        }

        return $value;
    }

    /** Requests one person may file in a payroll cycle (24th to 23rd); null = no cap (the decision for now). */
    public static function monthlyCap(): ?int
    {
        $cap = config('hrm.early_leave.monthly_request_cap');

        return $cap === null || $cap === '' ? null : max(0, (int) $cap);
    }

    /** How many days a request may wait before its approvers are reminded. */
    public static function reminderAfterDays(): int
    {
        return max(1, (int) config('hrm.early_leave.reminder_after_days', 2));
    }

    /** How many days before the payroll cycle ends HR hears which days are still open. */
    public static function cycleEndWarningDays(): int
    {
        return max(0, (int) config('hrm.early_leave.cycle_end_warning_days', 3));
    }

    /**
     * Requests standing in the payroll cycle of this date: waiting or
     * approved, never rejected, returned or cancelled. Pending counts, as with
     * the work-from-home cap, or eight requests filed in a morning would each
     * look fine on their own.
     *
     * ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23".
     * The cap counts the 24-23 cycle (`PayrollCycle::containing`), so a
     * request on the 24th opens a fresh count. It counted the calendar month
     * before.
     */
    public static function usedIn(Employee $employee, Carbon $date): int
    {
        $cycle = PayrollCycle::containing($date);

        return Movement::query()
            ->where('employee_id', $employee->id)
            ->where('type', MovementType::EarlyLeave)
            ->whereNull('cancelled_at')
            ->whereDate('movement_date', '>=', $cycle['start']->toDateString())
            ->whereDate('movement_date', '<=', $cycle['end']->toDateString())
            ->whereHas('approvalRequest', fn ($query) => $query->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved]))
            ->count();
    }
}
