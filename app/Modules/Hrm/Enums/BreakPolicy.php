<?php

namespace App\Modules\Hrm\Enums;

/**
 * How break time is treated (POL-08).
 *
 * The questionnaire ticked only "varies by shift" and left the three concrete
 * treatments unticked — so the treatment itself lives on the shift rather than
 * being fixed in code. These are those three options.
 */
enum BreakPolicy: string
{
    case Paid = 'paid';
    case FixedDeduction = 'fixed_deduction';
    case ActualPunches = 'actual_punches';
    case MealWindow = 'meal_window';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid break (not deducted)',
            self::FixedDeduction => "Deduct the shift's break minutes",
            // ⚠ Correction C22: kept working but deprecated, never reinterpreted.
            // Alternating pairing leaves a pair open on an odd punch count, which
            // becomes a Missing Punch and holds payroll — the exact failure the
            // meal window mode exists to avoid. Nothing live uses it.
            self::ActualPunches => 'Deduct the actual break between punches (not recommended: an odd punch count holds payroll)',
            self::MealWindow => 'Meal window: lunch read from middle punches, safe fallback, never holds payroll',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Paid => 'Break is paid, so nothing is deducted from worked time.',
            self::FixedDeduction => "The shift's fixed break is deducted regardless of punches.",
            self::ActualPunches => 'Time between an out and the next in is already excluded from worked time.',
            self::MealWindow => 'The meal is resolved from punches inside the configured window; unusable punch data falls back to the standard break.',
        };
    }
}
