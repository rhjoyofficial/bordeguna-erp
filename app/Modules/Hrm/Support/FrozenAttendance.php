<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Support\Carbon;

/**
 * Rule #18 (approved 14 Oct 2026): once the payroll run for a cycle is
 * Approved (or Disbursed, or Locked), attendance for that cycle is frozen.
 *
 * The days stay exactly as they were when the run was approved, because the
 * approved figures were calculated from them. A later correction, forgiveness,
 * leave, movement, holiday change or punch import for those dates does not
 * re-judge the days; `SettleFrozenAttendance` prices the difference instead
 * and records it as an arrear or a deduction on the next open payroll.
 *
 * ⚠ The cycle is the payroll period that contains the date (24th to 23rd,
 * labelled with the month it ends in), read from the stored period itself so
 * this can never disagree with the run it is protecting.
 *
 * ⚠ Reviewed is NOT frozen: it can still be reopened as a new version, and the
 * rule names Approved as the moment the inputs are fixed.
 */
class FrozenAttendance
{
    /**
     * The approved-or-later run whose cycle covers this date, if any. Only the
     * latest version counts: an older approved version replaced by a reopened
     * one no longer fixes anything.
     */
    public static function runCovering(Carbon|string $date): ?PayrollRun
    {
        $run = PayrollInputWindow::periodContaining($date)?->latestRun();

        return $run !== null && self::freezes($run->status) ? $run : null;
    }

    public static function isFrozen(Carbon|string $date): bool
    {
        return self::runCovering($date) !== null;
    }

    public static function freezes(PayrollStatus $status): bool
    {
        return in_array($status, [PayrollStatus::Approved, PayrollStatus::Disbursed, PayrollStatus::Locked], true);
    }

    /**
     * The sentence the person making the change sees, wherever they made it.
     *
     * @param  array{adjustment: mixed, difference: float, target: mixed}  $result
     */
    public static function message(PayrollRun $run, array $result): string
    {
        $label = $run->period?->label() ?? 'that cycle';

        if ($result['adjustment'] === null) {
            return "Payroll for {$label} is already approved, so its attendance stays as it was approved. This change makes no money difference to it.";
        }

        return sprintf(
            'Payroll for %s is already approved, so its attendance stays as it was approved. The difference (%s %s) is recorded as %s on the %s payroll.',
            $label,
            $result['difference'] > 0 ? 'owed to the employee' : 'owed back',
            number_format(abs($result['difference']), 2),
            $result['difference'] > 0 ? 'an arrear' : 'a deduction',
            $result['target']?->label() ?? 'next open',
        );
    }

    /**
     * Shown as the page's warning on the request that made the change. Domain
     * code flashing a message is unusual here; it is done once, in this one
     * place, because the change can come from six different screens (rule #18
     * asks for a clear message wherever the person makes it).
     */
    public static function tell(string $message): void
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return;
        }

        $existing = request()->session()->get('warning');
        $merged = is_string($existing) && $existing !== '' && ! str_contains($existing, $message)
            ? $existing.' '.$message
            : $message;

        request()->session()->flash('warning', $merged);
    }
}
