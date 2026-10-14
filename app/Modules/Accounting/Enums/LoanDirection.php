<?php

namespace App\Modules\Accounting\Enums;

/**
 * Which way the money went (loans plan v2, phase 2): lent by the business
 * (an asset: 1175 group or 1270) or borrowed by it (a liability: 2126, 2210,
 * 2220, 2230).
 */
enum LoanDirection: string
{
    case Given = 'given';
    case Taken = 'taken';

    public function label(): string
    {
        return match ($this) {
            self::Given => 'Loan given',
            self::Taken => 'Loan taken',
        };
    }

    /**
     * +1 when the loan's balance is a debit (money lent), -1 when it is a
     * credit (money borrowed): what turns a signed ledger amount into "how
     * much is owed" for this loan.
     */
    public function sign(): int
    {
        return $this === self::Given ? 1 : -1;
    }
}
