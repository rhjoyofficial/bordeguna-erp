<?php

namespace App\Modules\Hrm\Payroll\Data;

use Illuminate\Support\Carbon;

/**
 * The dates one payroll month covers, and the divisor its day rate uses.
 *
 * These are two different quantities and the plan flags conflating them as a
 * risk. Correction C7b sets the period at the 24th through the 23rd — so July
 * 2026 runs 24 June to 23 July — while PAY-02 fixes the divisor at 30
 * regardless of how many calendar days that range actually contains. A day rate
 * is Gross ÷ 30, never Gross ÷ (days in the range).
 */
final readonly class PayrollPeriodSpec
{
    public function __construct(
        public int $year,
        public int $month,
        public Carbon $start,
        public Carbon $end,
        public int $divisorDays,
    ) {}

    /**
     * Correction C7b: the month labelled July 2026 covers 24 June to 23 July.
     * The literal reading of PAY-01 would overlap consecutive cycles by a day
     * and risk paying it twice.
     */
    public static function forMonth(int $year, int $month, int $cutoffDay = 24, int $divisorDays = 30): self
    {
        $end = Carbon::create($year, $month, $cutoffDay - 1)->startOfDay();
        $start = $end->copy()->startOfDay()->subMonthNoOverflow()->day($cutoffDay);

        return new self($year, $month, $start, $end, $divisorDays);
    }

    public function label(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    public function describe(): string
    {
        return sprintf(
            '%s covers %s to %s, paid on a fixed %d-day divisor.',
            $this->label(),
            $this->start->format('j F'),
            $this->end->format('j F Y'),
            $this->divisorDays,
        );
    }

    /**
     * Calendar days the employee was actually on the books within this period —
     * PAY-05's joining proration and PAY-06's "up to the last working date"
     * both measure in calendar days.
     */
    public function employedDays(?Carbon $joining, ?Carbon $separation): int
    {
        $from = $joining !== null && $joining->gt($this->start) ? $joining->copy() : $this->start->copy();
        $to = $separation !== null && $separation->lt($this->end) ? $separation->copy() : $this->end->copy();

        if ($to->lt($from)) {
            return 0;
        }

        return (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
    }

    public function calendarDays(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }
}
