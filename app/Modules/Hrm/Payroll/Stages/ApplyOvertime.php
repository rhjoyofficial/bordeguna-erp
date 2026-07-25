<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;

/**
 * Stage 5 — overtime (ADJ-01, ADJ-02 via Correction C7a, ADJ-03).
 *
 * The hours arrive as an input rather than being derived from punches, because
 * ADJ-01 ticked "only approved overtime assignment" — overtime is authorised,
 * not inferred. That also keeps the attendance engine out of it entirely.
 *
 * **The rate is configuration, not code.** ADJ-02 was left unticked, and
 * Correction C7a seeded it with ADJ-02's own first option, Basic ÷ 30 ÷ 8. If
 * the client confirms a different formula the config changes and this class
 * does not — which is the difference between an unresolved rule held openly and
 * one guessed into the engine.
 *
 * ADJ-03's rounding and caps are applied before pricing: below the eligible
 * minimum nothing is paid, hours round down to the configured unit, and the
 * monthly ceiling is enforced. The daily ceiling needs per-day records and
 * belongs with the overtime assignments in Phase 3d.
 */
class ApplyOvertime implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;
        $config = $context->config;

        $hours = max(0.0, $context->overtimeHours);

        if ($hours <= 0) {
            return $computation;
        }

        $minutes = $hours * 60;

        // ADJ-03: a stint shorter than the eligible minimum earns nothing.
        if ($minutes < $config->overtimeMinimumMinutes) {
            return $computation->note(sprintf(
                'Overtime of %d minutes is below the %d minute minimum and was not paid.',
                (int) round($minutes),
                $config->overtimeMinimumMinutes,
            ));
        }

        // ADJ-03: round down to the configured unit.
        $unit = max(1, $config->overtimeRoundingMinutes);
        $roundedMinutes = floor($minutes / $unit) * $unit;
        $payableHours = round($roundedMinutes / 60, 4);

        $capped = false;

        if ($payableHours > $config->overtimeMonthlyMaxHours) {
            $payableHours = $config->overtimeMonthlyMaxHours;
            $capped = true;
        }

        $rate = $context->overtimeHourlyRate();
        $amount = round($payableHours * $rate, 2);

        $computation = $computation->withLine(new PayslipLineData(
            code: 'overtime',
            label: 'Overtime',
            type: SalaryComponentType::Earning,
            amount: $amount,
            quantity: $payableHours,
            rate: round($rate, 4),
            formula: sprintf(
                '%s ÷ %d ÷ %s × %s hour%s',
                number_format($context->overtimeRateBaseAmount(), 2),
                $config->divisorDays,
                self::trim($config->standardWorkingHours),
                self::trim($payableHours),
                $payableHours == 1.0 ? '' : 's',
            ),
            source: 'overtime_assignment',
        ));

        if ($capped) {
            $computation = $computation->note(sprintf(
                'Overtime capped at the %s hour monthly maximum (%s hours were recorded).',
                self::trim($config->overtimeMonthlyMaxHours),
                self::trim($hours),
            ));
        }

        return $computation;
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
