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
     * A version that was thrown away rather than paid.
     *
     * ⚠⚠ Neither of these is a decision to protect: the run is void, the
     * period is unpaid, and the only sane next step is a fresh version -
     * which `CalculatePayroll::openRun` already produces. Treating them as
     * "past Calculated" made the period a DEAD END, because a rejected run
     * is not rerunnable and not reopenable either, so the refusal advised a
     * door that does not exist. It stranded Joy's real August payroll twice
     * on 24 Aug 2026: once cancelled, then once rejected.
     *
     * The rejected version itself is never rewritten - it stays as the
     * record that this payroll was turned down, which is the whole reason
     * WFL-02 gave it its own state instead of sending it back to Draft.
     */
    public function isVoided(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected], true);
    }

    /**
     * The wages for this period have actually left the business.
     *
     * ⚠⚠ NOT the same question as isFinal(), and the difference is money.
     * isFinal() also counts Rejected and Cancelled, where the period is void
     * and UNPAID - a fresh version is still coming, so anything wanting to
     * reach that month by adjustment still can. Only Disbursed and Locked mean
     * the salary is in somebody's hands and a late line would appear in a month
     * already signed off and paid.
     *
     * ⚠ It lives here rather than as a string comparison at the call site so a
     * ninth case cannot slip past a `=== 'disbursed'` written somewhere else.
     */
    public function isPaidOut(): bool
    {
        return in_array($this, [self::Disbursed, self::Locked], true);
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
