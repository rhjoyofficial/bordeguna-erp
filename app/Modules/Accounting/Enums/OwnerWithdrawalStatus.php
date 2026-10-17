<?php

namespace App\Modules\Accounting\Enums;

/**
 * draft     being written; may be edited, deleted, authorised (a document a
 *           posting sent back for re-approval comes here, with its reason)
 * approved  authorised: the FIFO batch of every line is reserved and its cost
 *           frozen; waiting to be posted (handed over)
 * posted    stock out and journal written; frozen for ever
 */
enum OwnerWithdrawalStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Authorised',
            self::Posted => 'Posted',
        };
    }
}
