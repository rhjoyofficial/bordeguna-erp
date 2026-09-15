<?php

namespace App\Modules\Crm\Enums;

/**
 * How much a shop matters to the business: A, B or C, or not rated yet.
 *
 * ⭐ Joy, answer 6 (14 Sep 2026): the OFFICER sets it and the ASM can change it.
 * Both reviews had recommended the ASM alone; he chose the person who stands in
 * the shop. A new shop starts Unrated, which is a NULL column, not a fourth case.
 */
enum LeadImportance: string
{
    case A = 'A';

    case B = 'B';

    case C = 'C';

    public function label(): string
    {
        return $this->value;
    }
}
