<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Support\CompensatoryCredit;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises an overtime assignment (ADJ-01, ADJ-03).
 *
 * ADJ-01 ticked two things at once — "specific employment type/grade" and "only
 * approved overtime assignment" — while naming no type and no grade. Correction
 * C8d resolved that without inventing a category: eligibility is a flag on the
 * effective-dated salary structure, checked here **as at the date worked**, so a
 * promotion that grants overtime eligibility does not retroactively make last
 * March's hours claimable.
 *
 * ADJ-03's daily four-hour ceiling is enforced here rather than in the engine,
 * for the reason the engine's own docblock gave when it deferred it: a per-day
 * limit needs a per-day record, and the engine sees a whole period at once.
 * Refusing at the point of request also means nobody works hours they will later
 * be told are unpayable.
 */
class RequestOvertime
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        string $workDate,
        float $hours,
        bool $isHolidayWork,
        string $reason,
        User $requestedBy,
        ?PayrollConfig $config = null,
    ): OvertimeAssignment {
        $config ??= PayrollConfig::defaults();
        $date = Carbon::parse($workDate)->startOfDay();

        if ($hours <= 0) {
            throw ValidationException::withMessages(['hours' => 'Overtime must be more than zero hours.']);
        }

        $this->guardEligibility($employee, $date);
        $this->guardDailyCeiling($hours, $isHolidayWork, $config);
        $this->guardDuplicate($employee, $date);

        // ⚠ Paid overtime is priced by the month it was worked in, so that
        // month must still take inputs. Holiday work is paid in leave, not
        // money, and is not held to it.
        if (! $isHolidayWork) {
            PayrollInputWindow::assertAccepts(PayrollInputWindow::periodContaining($date), 'work_date');
        }

        return DB::transaction(function () use ($employee, $date, $hours, $isHolidayWork, $reason, $requestedBy) {
            $assignment = OvertimeAssignment::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'hours' => round($hours, 2),
                'is_holiday_work' => $isHolidayWork,
                'reason' => $reason,
                'requested_by' => $requestedBy->id,
            ]);

            $approval = $assignment->submitForApproval($requestedBy);
            $this->notify->handle($approval, $assignment->summaryLine());

            return $assignment->fresh();
        });
    }

    /**
     * ADJ-01 / Correction C8d.
     *
     * @throws ValidationException
     */
    private function guardEligibility(Employee $employee, Carbon $date): void
    {
        $structure = SalaryStructure::activeFor($employee->id, $date);

        if ($structure === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no salary structure in force on that date, so overtime eligibility cannot be established.',
            ]);
        }

        if (! $structure->overtime_eligible) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee is not marked overtime-eligible on their salary structure (ADJ-01).',
            ]);
        }
    }

    /**
     * ADJ-03's daily maximum. It caps *payable* overtime, so holiday work — which
     * ADJ-04 compensates in leave rather than money — is measured against
     * `CompensatoryCredit`'s own one-day ceiling instead.
     *
     * @throws ValidationException
     */
    private function guardDailyCeiling(float $hours, bool $isHolidayWork, PayrollConfig $config): void
    {
        if ($isHolidayWork) {
            return;
        }

        if ($hours > $config->overtimeDailyMaxHours) {
            throw ValidationException::withMessages([
                'hours' => sprintf(
                    'ADJ-03 caps overtime at %s hours a day.',
                    rtrim(rtrim(number_format($config->overtimeDailyMaxHours, 2), '0'), '.'),
                ),
            ]);
        }
    }

    /**
     * One assignment per employee-day. Two rows for the same date would slip
     * past the daily ceiling one half at a time.
     *
     * @throws ValidationException
     */
    private function guardDuplicate(Employee $employee, Carbon $date): void
    {
        $exists = OvertimeAssignment::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'work_date' => 'An overtime assignment already exists for this employee on that date.',
            ]);
        }
    }

    /**
     * What a holiday stint would be worth in compensatory leave, for the form to
     * show before anyone commits to it.
     */
    public function previewCompensatoryDays(float $hours, ?PayrollConfig $config = null): float
    {
        return CompensatoryCredit::daysFor($hours, $config ?? PayrollConfig::defaults());
    }
}
