<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One processed employee-day. Derived data — recalculable by design, unlike the
 * raw punches it was computed from.
 */
class DailyAttendance extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * Every flag the meal resolver can raise (C21/C24). One list, referenced by
     * the grid filter and the compliance report, so a new flag added in the
     * resolver is added here once rather than discovered missing in two screens.
     */
    public const MEAL_FLAGS = [
        'meal_not_punched',
        'meal_punch_incomplete',
        'meal_punches_unreadable',
        'meal_multiple_exits',
        'meal_overrun',
        'meal_late_return',
        'punch_outside_meal_window',
        'meal_config_incomplete',
        'meal_window_outside_shift',
    ];

    protected $fillable = [
        'business_id',
        'employee_id',
        'attendance_date',
        'status',
        'consequence',
        'first_in',
        'last_out',
        'worked_minutes',
        'break_minutes',
        'payable_minutes',
        'late_minutes',
        'early_leave_minutes',
        'grace_window_key',
        'occurrence_number',
        'is_provisional',
        'holds_payroll',
        'flags',
        'calculation',
        'policy_id',
        'policy_version',
        'shift_id',
        'finalized_at',
        'finalized_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceStatus::class,
            'consequence' => AttendanceConsequence::class,
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'payable_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'occurrence_number' => 'integer',
            'is_provisional' => 'boolean',
            'holds_payroll' => 'boolean',
            'flags' => 'array',
            'calculation' => 'array',
            'policy_version' => 'integer',
            'finalized_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * A finalised day must not be silently recomputed — that is a human's
     * decision made on the record (POL-06). Reprocessing skips these unless
     * explicitly told otherwise.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /**
     * Did the employee actually report for work on this day (Correction C14)?
     *
     * This is the eligibility rule for a per-day allowance such as TA & DA, and
     * it is deliberately **not** `presentDays`. `presentDays` measures payable
     * working fractions for salary proration; this measures whether somebody
     * turned up. The two are similar today and mean different things, and
     * keeping them apart is what stops a future change to proration silently
     * repricing a travel allowance.
     *
     * Joy's rule, 2026-07-29: eligibility follows whether the employee reported
     * for work, never their salary status and never a payable fraction. So:
     *
     *  - Present, Late (**every band**, including one whose day's salary was
     *    forfeited), Field duty → yes. They travelled and reported.
     *  - **Half day → yes, in full.** The journey was made in full, so this
     *    method answers a whole day where `presentDays` counts 0.5.
     *  - **Approved work on a holiday or weekly off → yes.** The status stays
     *    `Holiday`/`WeeklyOff` in that case, so it is checked explicitly here.
     *    Unapproved holiday work is flagged by POL-09 and does not count.
     *  - Approved leave, Absent, no check-in at all → no.
     *  - Missing punch → no; PAY-09 holds the payslip until it is corrected.
     */
    public function reportedForWork(): bool
    {
        if ($this->status->establishesReportingForWork()) {
            return true;
        }

        if (! in_array($this->status, [AttendanceStatus::Holiday, AttendanceStatus::WeeklyOff], true)) {
            return false;
        }

        // POL-09: worked, and not withheld for want of approval. The flag is
        // the authority here rather than payable_minutes, because an approved
        // holiday's payable minutes are a payroll question and this is not.
        return $this->worked_minutes > 0
            && ! in_array('unapproved_holiday_work', $this->flags ?? [], true);
    }

    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('attendance_date', Carbon::parse($date)->toDateString());
    }

    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where('is_provisional', true);
    }

    /**
     * How many times this employee already hit a given lateness band earlier in
     * the same PAYROLL CYCLE — the running counter POL-03's ladders
     * escalate on. Counted strictly *before* the date being processed so
     * reprocessing a day cannot count itself.
     *
     * ⚠⚠ THE CYCLE, NOT THE CALENDAR MONTH, AND IT IS A MONEY RULE.
     * PAY-01 records this company's month as the 24th to the 23rd and leaves
     * "Calendar month" explicitly unticked. These ladders end in a salary
     * deduction, so the month they count over has to be the month that is
     * actually paid.
     *
     * Resetting on the 1st did two wrong things at once, both measured on
     * real data 9 Sep 2026: it carried the previous pay period's lates into
     * the start of the new one (one employee opened a period on occurrence 9
     * and was deducted four times for lateness already paid for), and then it
     * handed out a free reset in the MIDDLE of the period, on the 1st.
     *
     * ⚠ A start day of 1 collapses this back to the plain calendar month, so
     * a business configured that way behaves exactly as it did before.
     *
     * @return array<string, int>
     */
    public static function priorOccurrences(int $employeeId, Carbon $date): array
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('grace_window_key')
            // whereDate on both bounds, not whereBetween: `attendance_date` is a
            // date cast stored as "Y-m-d 00:00:00", so a plain string upper
            // bound of "Y-m-d" compares as less than the stored value and
            // silently drops that day — which would under-count the ladder.
            ->whereDate('attendance_date', '>=', PayrollCycle::containing($date)['start']->toDateString())
            ->whereDate('attendance_date', '<', $date->toDateString())
            ->selectRaw('grace_window_key, count(*) as total')
            ->groupBy('grace_window_key')
            ->pluck('total', 'grace_window_key')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
