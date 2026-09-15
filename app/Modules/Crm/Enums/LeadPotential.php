<?php

namespace App\Modules\Crm\Enums;

/**
 * The officer's estimate of what a shop could buy (Joy, answer 5, 14 Sep 2026).
 *
 * ⚠ An ESTIMATE, kept apart from the purchase-value band that will one day be
 * derived from real sales. The two answer different questions - "what could it
 * be" and "what has it been" - and a single merged field would let a guess
 * overwrite a fact.
 */
enum LeadPotential: string
{
    case Low = 'low';

    case Medium = 'medium';

    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }
}
