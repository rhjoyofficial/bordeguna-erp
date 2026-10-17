<?php

namespace App\Modules\Purchasing\Enums;

/**
 * draft     being written; may be edited, deleted, submitted (a rejected
 *           document comes back here with its reviewer's note)
 * approved  the approval request was approved; waiting to be posted
 * posted    movements and journal written; frozen for ever
 *
 * "Waiting for approval" is a draft whose approval request is pending: the
 * request, not a second status column, says who may act (Approvable).
 */
enum LandedCostStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Posted => 'Posted',
        };
    }
}
