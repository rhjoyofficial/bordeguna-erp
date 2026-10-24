<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\DateRuleSummary;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): Cancel an APPROVED payroll
 * input (an overtime, a bonus award, a salary revision) while the payroll
 * month it belongs to still takes inputs (FINAL-PLAN 3.4, "Open month: dropped
 * from pay"). The row stays, marked with the reason; payroll skips it.
 *
 *   overtime          the month holding its work date; holiday work also
 *                     takes back its compensatory leave, refused when those
 *                     days were already taken
 *   bonus award       its bonus's payroll month
 *   salary revision   the month it takes effect in; the salary before it
 *                     comes back. Refused when it raised back-pay for months
 *                     already paid, when a later salary followed it, or when
 *                     a payroll that no longer takes inputs was worked out
 *                     with it.
 *
 * ⛔ A locked, approved or paid payroll is never touched (August 2026 is locked
 * at net 230,805): a month that no longer takes inputs refuses the cancel in a
 * sentence that says what to do instead (an adjustment on the next open
 * month). A pending input keeps its own Withdraw and Reject.
 *
 * Not an engine correction: none of these posts a journal (the payroll run
 * that pays them does), so there is no date exception and no Tk 20,000 rule;
 * the module runs it (CancelPayrollInput), asking this policy again under its
 * lock. Who: the payroll manager (overtime, bonus) or the salary manager
 * (revision).
 */
class PayrollInputCancelPolicy
{
    public function permissionFor(Model $input): string
    {
        return $input instanceof SalaryRevision ? 'hrm.salary.manage' : 'hrm.payroll.manage';
    }

    /** @return list<array<string, mixed>> the screen's actions list (Cancel), empty when not offered */
    public function actions(Model $input, User $user): array
    {
        if (! $user->can($this->permissionFor($input)) || ! $this->approved($input)) {
            return [];
        }

        return [$this->availability($input, $user)->toArray()];
    }

    public function availability(Model $input, User $user): ActionAvailability
    {
        $blockers = [];

        if (! $user->can($this->permissionFor($input))) {
            $blockers[] = Blocker::noPermission(DocumentAction::Cancel);
        }

        $blockers = [...$blockers, ...$this->blockers($input)];
        $period = $this->periodOf($input);
        $sentence = $blockers === [] ? $this->sentence($input, $period) : null;

        return new ActionAvailability(
            action: DocumentAction::Cancel,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $this->permissionFor($input),
            primary: true,
            requiresReason: true,
            reasonContext: 'cancellation',
            approval: ApprovalRoute::None,
            date: $sentence === null ? null : new DateRuleSummary('dropped', $sentence),
        );
    }

    /** The payroll month the input is paid in, when it exists yet. */
    public function periodOf(Model $input): ?PayrollPeriod
    {
        return match (true) {
            $input instanceof OvertimeAssignment => PayrollInputWindow::periodContaining($input->work_date),
            $input instanceof BonusAward => $input->event?->period,
            $input instanceof SalaryRevision => PayrollInputWindow::periodContaining($input->effective_from),
            default => throw new InvalidArgumentException('Not a payroll input.'),
        };
    }

    public function label(Model $input): string
    {
        return match (true) {
            $input instanceof OvertimeAssignment => "Overtime #{$input->id}",
            $input instanceof BonusAward => "Bonus award #{$input->id}",
            $input instanceof SalaryRevision => "Salary revision #{$input->id}",
            default => 'This input',
        };
    }

    /** @return list<Blocker> */
    public function blockers(Model $input): array
    {
        $label = $this->label($input);

        if ($input->cancelled_at !== null) {
            return [new Blocker('already_cancelled', "{$label} was already cancelled on ".Carbon::parse($input->cancelled_at)->format('j M').'.')];
        }

        if (! $this->approved($input)) {
            return [new Blocker('not_approved', "{$label} is not approved, so there is nothing to cancel. Withdraw or reject it instead.")];
        }

        $period = $this->periodOf($input);

        if (! PayrollInputWindow::accepts($period)) {
            $status = strtolower((string) $period->latestRun()?->status->label());

            return [new Blocker('month_closed', "The {$period->label()} payroll is already {$status}, so {$label} stays as paid. Put an adjustment on the next open month instead.")];
        }

        if ($input instanceof OvertimeAssignment && $input->is_holiday_work && (float) $input->compensatory_days > 0) {
            $type = LeaveType::query()->where('code', LeaveType::COMPENSATORY)->first();
            $balance = $type === null ? 0.0 : app(LeaveBalance::class)->for((int) $input->employee_id, (int) $type->id, (int) Carbon::parse($input->work_date)->year);

            if ($balance + 0.001 < (float) $input->compensatory_days) {
                return [new Blocker('leave_taken', "The compensatory leave {$label} gave was already taken, so it cannot be cancelled.")];
            }
        }

        if ($input instanceof SalaryRevision) {
            return $this->revisionBlockers($input, $label);
        }

        return [];
    }

    /** @return list<Blocker> */
    private function revisionBlockers(SalaryRevision $revision, string $label): array
    {
        if (abs((float) $revision->arrear_amount) >= 0.01) {
            return [new Blocker('arrear_raised', "{$label} paid back-pay for months already paid, so it cannot be cancelled. Record a new revision instead.")];
        }

        $structure = $revision->salary_structure_id === null ? null : SalaryStructure::query()->withoutGlobalScopes()->find($revision->salary_structure_id);

        if ($structure === null) {
            return [new Blocker('no_structure', "{$label} has no salary of its own to take back. Ask the administrator to check it.")];
        }

        $later = SalaryStructure::query()->withoutGlobalScopes()
            ->where('employee_id', $structure->employee_id)
            ->whereKeyNot($structure->id)
            ->whereDate('effective_from', '>', Carbon::parse($structure->effective_from)->toDateString())
            ->exists();

        if ($later) {
            return [new Blocker('later_salary', "A later salary followed {$label}, so cancel that one first.")];
        }

        $paid = Payslip::query()->withoutGlobalScopes()
            ->where('salary_structure_id', $structure->id)
            ->with('run.period')
            ->get()
            ->first(fn (Payslip $slip) => $slip->run !== null && ! $slip->run->status->isRerunnable() && ! $slip->run->status->isVoided());

        if ($paid !== null) {
            return [new Blocker('paid_with_it', "The {$paid->run->period?->label()} payroll was worked out with {$label} and no longer takes inputs, so it stays. Record a new revision instead.")];
        }

        return [];
    }

    private function sentence(Model $input, ?PayrollPeriod $period): string
    {
        $month = $period?->label();
        $sentence = match (true) {
            $input instanceof SalaryRevision => 'The salary before it comes back'.($month ? " from the {$month} payroll" : '').'.',
            default => $month ? "Dropped from the {$month} payroll." : 'Dropped before any payroll pays it.',
        };

        if ($input instanceof OvertimeAssignment && $input->is_holiday_work && (float) $input->compensatory_days > 0) {
            $sentence .= ' Its '.rtrim(rtrim(number_format((float) $input->compensatory_days, 2), '0'), '.').' days of compensatory leave are taken back.';
        }

        if ($period?->latestRun()?->status === PayrollStatus::Calculated) {
            $sentence .= ' Recalculate that payroll so its payslips drop it too.';
        }

        return $sentence;
    }

    private function approved(Model $input): bool
    {
        return match (true) {
            $input instanceof OvertimeAssignment => $input->isApplied(),
            $input instanceof BonusAward => $input->isApproved(),
            $input instanceof SalaryRevision => $input->isApplied(),
            default => false,
        };
    }
}
