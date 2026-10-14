<?php

namespace App\Modules\Accounting\Enums;

/**
 * A loan agreement's life (loans plan v2, phase 2):
 *
 *   draft -> pending_approval (the Owner) -> approved -> active -> settled
 *                                                              -> written_off
 *   draft or approved, with no money moved -> cancelled
 *
 * ⚠ "Overdue" is not a status. It is computed from the schedule every time it
 * is asked, so it can never be stale.
 */
enum LoanStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Active = 'active';
    case Settled = 'settled';
    case WrittenOff = 'written_off';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Waiting for the Owner',
            self::Approved => 'Approved, no money yet',
            self::Active => 'Active',
            self::Settled => 'Settled',
            self::WrittenOff => 'Written off',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Approved and not finished: its money moves through the register. */
    public function isLive(): bool
    {
        return in_array($this, [self::Approved, self::Active], true);
    }

    /** Approved at some point: the register and the ledger both know it. */
    public function wasApproved(): bool
    {
        return in_array($this, [self::Approved, self::Active, self::Settled, self::WrittenOff], true);
    }

    /** @return list<string> */
    public static function liveValues(): array
    {
        return [self::Approved->value, self::Active->value];
    }

    /** @return list<string> */
    public static function approvedValues(): array
    {
        return [self::Approved->value, self::Active->value, self::Settled->value, self::WrittenOff->value];
    }
}
