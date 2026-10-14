<?php

namespace App\Modules\Accounting\Enums;

/**
 * The contractual order a repayment settles what is due (ChatGPT confirmation,
 * point 7): interest first is the default, never an unbreakable rule.
 */
enum LoanWaterfall: string
{
    case InterestFirst = 'interest_first';
    case PrincipalFirst = 'principal_first';

    public function label(): string
    {
        return match ($this) {
            self::InterestFirst => 'Interest first, then principal',
            self::PrincipalFirst => 'Principal first, then interest',
        };
    }
}
