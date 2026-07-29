<?php

namespace App\Modules\Hrm\Payroll\Data;

/**
 * What Attendance and Leave say about the period — facts Payroll consumes and
 * never recomputes.
 *
 * This object is the boundary the architecture rests on. Every figure here was
 * decided elsewhere: `absentDays` by the attendance engine's POL-06 status,
 * `unpaidLeaveDays` by LEV-07's conversion, and `deductibleDays` by POL-03's
 * occurrence ladder. Payroll's only job is to price them.
 *
 * `deductibleDays` deserves the emphasis: Attendance emits
 * `AttendanceConsequence::Deduction` as a *fact about the day*, deliberately
 * not an amount, because pricing a day needs a salary structure Attendance
 * knows nothing about. Four late arrivals in PAY-13 are a warning only, so this
 * is zero and no money moves.
 */
final readonly class AttendanceFacts
{
    public function __construct(
        public float $presentDays,
        public float $absentDays,
        public float $paidLeaveDays,
        public float $unpaidLeaveDays,
        public int $lateOccurrences,
        public int $lateMinutes,
        /** Days of pay POL-03's ladder decided should be deducted (POL-02 keeps status and consequence separate). */
        public float $deductibleDays,
        /** PAY-09: an unresolved missing punch blocks this employee's line entirely. */
        public bool $hasUnresolvedMissingPunch,
        /**
         * Days the employee actually reported for work (Correction C14) — the
         * eligibility count for a per-day allowance such as TA & DA.
         *
         * ⚠⚠ **This is not `presentDays`, and must never be replaced by it.**
         * `presentDays` measures payable working fractions for salary
         * proration: a half day counts 0.5 there, and an approved holiday
         * worked is absent from it entirely. This one counts whether somebody
         * turned up, so a half day is a **whole** 1 and an approved holiday
         * worked is included. They are similar today and mean different things,
         * and keeping them apart is what stops a future change to proration
         * silently repricing a travel allowance.
         *
         * Typed `int` rather than `float` deliberately: there is no such thing
         * as a fraction of reporting for work, and the type says so before
         * anyone is tempted to halve it.
         *
         * ⚠ Defaulted to 0 so it is **fail-closed**: a caller that forgets it
         * pays no allowance, rather than paying one nobody is entitled to.
         */
        public int $daysReportedForWork = 0,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, false, 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'present_days' => $this->presentDays,
            'absent_days' => $this->absentDays,
            'paid_leave_days' => $this->paidLeaveDays,
            'unpaid_leave_days' => $this->unpaidLeaveDays,
            'late_occurrences' => $this->lateOccurrences,
            'late_minutes' => $this->lateMinutes,
            'deductible_days' => $this->deductibleDays,
            'has_unresolved_missing_punch' => $this->hasUnresolvedMissingPunch,
            'days_reported_for_work' => $this->daysReportedForWork,
        ];
    }
}
