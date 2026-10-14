<?php

namespace App\Modules\Accounting\Enums;

/**
 * What kind of legal person a counterparty is (loans plan v2, phase 1).
 *
 * ⚠ The kind decides nothing in the books yet. It is recorded because Phase 2
 * needs it: interest paid to a bank or NBFI is treated differently for TDS
 * (Income Tax Act 2023, s.104) from interest paid to anyone else, and the
 * accountant has to see which is which before that rule is switched on.
 */
enum CounterpartyKind: string
{
    case Company = 'company';
    case Individual = 'individual';
    case Bank = 'bank';
    case Nbfi = 'nbfi';
    case Government = 'government';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Individual => 'Individual',
            self::Bank => 'Bank',
            self::Nbfi => 'NBFI (finance company)',
            self::Government => 'Government body',
            self::Other => 'Other',
        };
    }
}
