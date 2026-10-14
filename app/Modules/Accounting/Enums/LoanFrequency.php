<?php

namespace App\Modules\Accounting\Enums;

/** How often an installment or an interest payment falls due. */
enum LoanFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case AtMaturity = 'at_maturity';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::AtMaturity => 'At maturity',
        };
    }

    /** Months between two due dates, or null when everything falls due at maturity. */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::AtMaturity => null,
        };
    }
}
