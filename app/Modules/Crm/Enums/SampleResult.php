<?php

namespace App\Modules\Crm\Enums;

/**
 * The Owner's verdict on a supplier's sample (Joy, answer 15, 14 Sep 2026).
 *
 * ⚠ Retest is a verdict, not a pending state: it says "not good enough to decide
 * on", and the next sample the supplier sends is a NEW row with its own verdict.
 * No sample is ever re-judged, so the history keeps every answer that was given.
 */
enum SampleResult: string
{
    case Pass = 'pass';

    case Fail = 'fail';

    case Retest = 'retest';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::Fail => 'Fail',
            self::Retest => 'Retest',
        };
    }
}
