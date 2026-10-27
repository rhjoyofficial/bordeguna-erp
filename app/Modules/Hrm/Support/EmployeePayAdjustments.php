<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Notifications\PayAdjustmentDecidedNotification;

/**
 * ⭐ My deductions (Joy, 27 Oct 2026): "Office gave penalty to Nazmul ... so he
 * can see his things." The employee's own view of the adjustments that change
 * his pay, before the payslip that carries them is visible to him.
 *
 * Two halves, one rule each:
 *
 *   upcoming()   his payable adjustments (approved, or made by the system)
 *                on a month whose payroll is not finished yet. Once that
 *                payroll is finished the line is on his payslip instead.
 *   approved()   the bell message when one is decided, and cancelled()
 *                when one that was payable is cancelled.
 *
 * ⚠ Pending ones are NOT shown. An adjustment is raised by HR about him, not
 * by him, and nothing else in self-service shows an employee an HR decision
 * that is still being made: he hears it once it is decided.
 *
 * ⚠ Self-scope is the caller's: every caller passes Employee::forUser() of the
 * signed-in user, so nobody reaches anyone else's lines through here.
 */
final class EmployeePayAdjustments
{
    /**
     * The run statuses at which an employee sees his payslip. PayslipController
     * reads the same list, so "finished" means one thing on both pages.
     *
     * @return array<int, PayrollStatus>
     */
    public static function finishedStatuses(): array
    {
        return [PayrollStatus::Approved, PayrollStatus::Disbursed, PayrollStatus::Locked];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function upcoming(Employee $employee): array
    {
        return PayrollAdjustment::query()
            ->payable()
            ->where('employee_id', $employee->id)
            ->whereDoesntHave('period.runs', fn ($runs) => $runs->whereIn('status', self::finishedStatuses()))
            ->with(['period', 'approvalRequest.reviewedBy:id,name'])
            ->get()
            ->sortBy(fn (PayrollAdjustment $a) => sprintf('%04d-%02d-%010d', $a->period?->year, $a->period?->month, $a->id))
            ->values()
            ->map(fn (PayrollAdjustment $a) => [
                'id' => $a->id,
                'month' => $a->period?->label(),
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'is_earning' => $a->type->isEarning(),
                'amount' => $a->type->isEarning() ? (float) $a->amount : -(float) $a->amount,
                'reason' => $a->reason,
                'decided_on' => ($a->approvalRequest?->reviewed_at ?? $a->created_at)?->toDateString(),
                'decided_by' => $a->approvalRequest?->reviewedBy?->name,
            ])
            ->all();
    }

    /** The bell message once an adjustment's last approval stage says yes. */
    public static function approved(PayrollAdjustment $adjustment, User $by): void
    {
        $adjustment->loadMissing(['employee.user', 'period']);

        self::tell($adjustment, sprintf(
            '%s %s of Tk %s will be %s your %s salary. Reason: %s. Decided by %s.',
            self::article($adjustment->type->label()),
            strtolower($adjustment->type->label()),
            self::money($adjustment->amount),
            $adjustment->type->isEarning() ? 'added to' : 'deducted from',
            $adjustment->period?->label(),
            rtrim((string) $adjustment->reason, '. '),
            $by->name,
        ));
    }

    /**
     * The bell message when a payable adjustment is cancelled. In a month that
     * still takes inputs it simply drops; in a closed one an opposite line on
     * a later month gives the money back (or takes it back).
     */
    public static function cancelled(PayrollAdjustment $adjustment, User $by, string $why, ?PayrollAdjustment $offset): void
    {
        $adjustment->loadMissing(['employee.user', 'period']);
        $earning = $adjustment->type->isEarning();
        $amount = self::money($adjustment->amount);

        $what = $offset === null
            ? sprintf(', so it will not be %s', $earning ? 'added' : 'deducted')
            : sprintf(
                '. Tk %s will be %s your %s salary to %s',
                $amount,
                $earning ? 'deducted from' : 'added to',
                $offset->loadMissing('period')->period?->label(),
                $earning ? 'take it back' : 'give it back',
            );

        self::tell($adjustment, sprintf(
            'The %s of Tk %s on your %s salary was cancelled%s. Reason: %s. Cancelled by %s.',
            strtolower($adjustment->type->label()),
            $amount,
            $adjustment->period?->label(),
            $what,
            rtrim($why, '. '),
            $by->name,
        ));
    }

    private static function tell(PayrollAdjustment $adjustment, string $summary): void
    {
        $adjustment->employee?->user?->notify(new PayAdjustmentDecidedNotification($summary, route('hrm.my-payslips')));
    }

    private static function article(string $word): string
    {
        return preg_match('/^[aeiou]/i', $word) === 1 ? 'An' : 'A';
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
