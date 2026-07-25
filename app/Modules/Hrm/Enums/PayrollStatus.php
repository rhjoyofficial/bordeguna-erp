<?php

namespace App\Modules\Hrm\Enums;

/**
 * The payroll lifecycle (WFL-02).
 *
 * The six main states are the ticked answer; Rejected and Cancelled come from
 * WFL-02's own recommendation and were confirmed by Joy. They matter because
 * without them a turned-down payroll has nowhere to sit but back in Draft,
 * which silently loses the fact that it was rejected and why.
 *
 * Transitions are declared here rather than scattered through controllers, so
 * "can this payroll still be re-run" has exactly one answer everywhere.
 */
enum PayrollStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case Locked = 'locked';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Calculated => 'Calculated',
            self::Reviewed => 'Reviewed',
            self::Approved => 'Approved',
            self::Disbursed => 'Disbursed',
            self::Locked => 'Locked',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return array<int, self>
     */
    public function transitionableTo(): array
    {
        return match ($this) {
            self::Draft => [self::Calculated, self::Cancelled],
            self::Calculated => [self::Reviewed, self::Cancelled],
            // Approval is not a free transition — it runs through the workflow
            // engine, so Reviewed advances only by an approval decision.
            self::Reviewed => [self::Cancelled],
            self::Approved => [self::Disbursed],
            self::Disbursed => [self::Locked],
            // A locked payroll reopens only through the WFL-08 unlock chain.
            self::Locked, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitionableTo(), true);
    }

    /**
     * WFL-05: a free re-run is allowed only while Draft or Calculated.
     * Anything further needs the reopen approval.
     */
    public function isRerunnable(): bool
    {
        return $this === self::Draft || $this === self::Calculated;
    }

    /**
     * WFL-06: before disbursement a reopen produces a new version. After it,
     * differences go to the next payroll as an arrear instead.
     */
    public function isReopenable(): bool
    {
        return in_array($this, [self::Reviewed, self::Approved], true);
    }

    /**
     * Past this point the figures are the record — WFL-05 forbids a direct
     * re-run, and WFL-06 sends corrections forward as adjustments.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Disbursed, self::Locked, self::Rejected, self::Cancelled], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }
}
