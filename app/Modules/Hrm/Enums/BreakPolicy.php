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

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid break (not deducted)',
            self::FixedDeduction => "Deduct the shift's break minutes",
            self::ActualPunches => 'Deduct the actual break between punches',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::Paid => 'Break is paid, so nothing is deducted from worked time.',
            self::FixedDeduction => "The shift's fixed break is deducted regardless of punches.",
            self::ActualPunches => 'Time between an out and the next in is already excluded from worked time.',
        };
    }
}
