<?php

namespace App\Modules\Hrm\Payroll;

/**
 * The payroll rules that are configuration rather than code.
 *
 * The overtime rate lives here because **ADJ-02 was left unticked** and
 * Correction C7a settled it as data seeded with ADJ-02's own first option,
 * `Basic ÷ 30 ÷ 8`. If the client confirms a different formula, this object
 * changes and the engine does not — which is the whole point of keeping an
 * unresolved rule as configuration instead of guessing it into the code.
 *
 * The remaining unanswered rules are deliberately absent rather than defaulted:
 * there is no statutory tax or PF calculation here (GOV-06), and no
 * negative-net carry-forward recovery rule (PAY-11's open half).
 */
final readonly class PayrollConfig
{
    public function __construct(
        /** PAY-02: a fixed 30-day divisor, not the calendar length of the period. */
        public int $divisorDays,
        /** ADJ-02's "Standard Working Hours" term. */
        public float $standardWorkingHours,
        /** Correction C7a: 'basic' (seeded) or 'gross'. */
        public string $overtimeRateBase,
        public float $overtimeMultiplier,
        /** ADJ-03. */
        public int $overtimeMinimumMinutes,
        public int $overtimeRoundingMinutes,
        /**
         * ADJ-03's daily ceiling. Enforced when an assignment is raised rather
         * than in the engine, because only a per-day record can be measured
         * against a per-day limit — the engine sees a whole period at once.
         */
        public float $overtimeDailyMaxHours,
        public float $overtimeMonthlyMaxHours,
        /** PAY-03: absence is deducted from Gross. */
        public string $absenceRateBase,
    ) {}

    public static function defaults(
        int $divisorDays = 30,
        float $standardWorkingHours = 8,
        string $overtimeRateBase = 'basic',
        float $overtimeMultiplier = 1.0,
        int $overtimeMinimumMinutes = 30,
        int $overtimeRoundingMinutes = 30,
        float $overtimeDailyMaxHours = 4,
        float $overtimeMonthlyMaxHours = 60,
        string $absenceRateBase = 'gross',
    ): self {
        return new self(
            $divisorDays,
            $standardWorkingHours,
            $overtimeRateBase,
            $overtimeMultiplier,
            $overtimeMinimumMinutes,
            $overtimeRoundingMinutes,
            $overtimeDailyMaxHours,
            $overtimeMonthlyMaxHours,
            $absenceRateBase,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return self::defaults(
            divisorDays: (int) ($config['divisor_days'] ?? 30),
            standardWorkingHours: (float) ($config['standard_working_hours'] ?? 8),
            overtimeRateBase: $config['overtime_rate_base'] ?? 'basic',
            overtimeMultiplier: (float) ($config['overtime_multiplier'] ?? 1.0),
            overtimeMinimumMinutes: (int) ($config['overtime_minimum_minutes'] ?? 30),
            overtimeRoundingMinutes: (int) ($config['overtime_rounding_minutes'] ?? 30),
            overtimeDailyMaxHours: (float) ($config['overtime_daily_max_hours'] ?? 4),
            overtimeMonthlyMaxHours: (float) ($config['overtime_monthly_max_hours'] ?? 60),
            absenceRateBase: $config['absence_rate_base'] ?? 'gross',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'divisor_days' => $this->divisorDays,
            'standard_working_hours' => $this->standardWorkingHours,
            'overtime_rate_base' => $this->overtimeRateBase,
            'overtime_multiplier' => $this->overtimeMultiplier,
            'overtime_minimum_minutes' => $this->overtimeMinimumMinutes,
            'overtime_rounding_minutes' => $this->overtimeRoundingMinutes,
            'overtime_daily_max_hours' => $this->overtimeDailyMaxHours,
            'overtime_monthly_max_hours' => $this->overtimeMonthlyMaxHours,
            'absence_rate_base' => $this->absenceRateBase,
        ];
    }
}
