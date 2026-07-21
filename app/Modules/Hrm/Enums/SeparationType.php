<?php

namespace App\Modules\Hrm\Enums;

/**
 * How an employee left (EMP-07). Recorded on separation together with the
 * effective date, which payroll reads to prorate the final month (PAY-06).
 */
enum SeparationType: string
{
    case Resignation = 'resignation';
    case Termination = 'termination';
    case Retirement = 'retirement';
    case ContractEnd = 'contract_end';
    case Absconding = 'absconding';
    case Death = 'death';
    case MutualSeparation = 'mutual_separation';
    case Layoff = 'layoff';

    public function label(): string
    {
        return match ($this) {
            self::Resignation => 'Resignation',
            self::Termination => 'Termination',
            self::Retirement => 'Retirement',
            self::ContractEnd => 'Contract end',
            self::Absconding => 'Absconding',
            self::Death => 'Death',
            self::MutualSeparation => 'Mutual separation',
            self::Layoff => 'Layoff / Redundancy',
        };
    }
}
