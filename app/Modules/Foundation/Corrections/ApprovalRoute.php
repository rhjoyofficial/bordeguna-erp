<?php

namespace App\Modules\Foundation\Corrections;

/**
 * Whether a correction executes now or waits (PHASE-0-CONTRACT v2, 1.8).
 *
 *   None              executes at once
 *   SecondPerson      above the limit: a different eligible person approves
 *                     (also when the Owner asks; then the Owner's own authority
 *                     covers a closed month, recorded as owner_authorised_by)
 *   OwnerClosedPeriod a closed month, asked by somebody who is not the Owner
 *   Both              a closed month AND above the limit, asked by somebody who
 *                     is not the Owner: the Owner approves, and is then both the
 *                     Owner gate and the independent second person
 */
enum ApprovalRoute: string
{
    case None = 'none';
    case SecondPerson = 'second_person';
    case OwnerClosedPeriod = 'owner_closed_period';
    case Both = 'both';

    public function workflowKey(): ?string
    {
        return match ($this) {
            self::None => null,
            self::SecondPerson => 'correction',
            self::OwnerClosedPeriod, self::Both => 'correction_closed_period',
        };
    }

    public function needsSecondPerson(): bool
    {
        return $this === self::SecondPerson || $this === self::Both;
    }

    public function needsOwnerApproval(): bool
    {
        return $this === self::OwnerClosedPeriod || $this === self::Both;
    }
}
