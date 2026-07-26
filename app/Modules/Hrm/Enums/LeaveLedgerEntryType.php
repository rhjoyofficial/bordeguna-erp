<?php

namespace App\Modules\Hrm\Enums;

/**
 * What moved a leave balance.
 *
 * Balances are never stored as a number — they are the sum of an append-only
 * ledger. That is what makes a balance explainable ("you had 20, took 12,
 * carried 8") and what makes the year-end job safe to rerun: a rerun either
 * finds its entry already posted or posts it once.
 */
enum LeaveLedgerEntryType: string
{
    case Grant = 'grant';
    case CarryForward = 'carry_forward';
    /**
     * ADJ-04 via Correction C8b — balance *earned* by working a holiday rather
     * than granted by policy. Its own case because the ledger's whole purpose is
     * to explain a balance, and filing this under "annual quota granted" would
     * misdescribe both where the day came from and why it cannot be encashed.
     */
    case CompensatoryCredit = 'compensatory_credit';
    case Taken = 'taken';
    case Lapse = 'lapse';
    case Encashment = 'encashment';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Grant => 'Annual quota granted',
            self::CarryForward => 'Carried forward',
            self::CompensatoryCredit => 'Earned by working a holiday',
            self::Taken => 'Leave taken',
            self::Lapse => 'Lapsed above the carry-forward cap',
            self::Encashment => 'Encashed',
            self::Adjustment => 'Manual adjustment',
        };
    }

    /**
     * Whether entries of this type add to a balance. Taken, lapse and
     * encashment are stored as negative days, so this is about intent rather
     * than sign — it is what the ledger view uses to explain a movement.
     */
    public function isCredit(): bool
    {
        return in_array($this, [self::Grant, self::CarryForward, self::CompensatoryCredit], true);
    }
}
