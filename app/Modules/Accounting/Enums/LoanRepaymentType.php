<?php

namespace App\Modules\Accounting\Enums;

/**
 * How the principal comes back: in equal installments (interest on the
 * declining balance), all at once at maturity, or on rows typed in by hand.
 */
enum LoanRepaymentType: string
{
    case EqualInstallments = 'equal_installments';
    case Bullet = 'bullet';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::EqualInstallments => 'Equal installments',
            self::Bullet => 'Bullet (all principal at maturity)',
            self::Custom => 'Custom schedule',
        };
    }
}
