<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\RevokeErpAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ⭐ 27 Sep 2026 (Joy): access ends on the LAST WORKING DAY, not the day a
 * resignation is recorded.
 *
 * SeparateEmployee disables the login at once only when the leaving date is
 * already in the past. A resignation dated today or in the future leaves the
 * login live; this command, run each morning, switches it off once the last
 * working day has passed (separation_date strictly before today), so the
 * person keeps access through their final day and loses it the morning after.
 *
 * Separation is a central event, so this reaches every business at once
 * (BusinessScope is dropped, exactly as SeparateEmployee closes assignments
 * across all businesses).
 *
 * Idempotent: a login already off is skipped, so running twice a day, or after
 * a missed day, changes nothing the second time.
 */
class DisableDueSeparations extends Command
{
    protected $signature = 'hrm:disable-due-separations {--dry-run : List who would be disabled without changing anything}';

    protected $description = 'Switch off the ERP login of anyone whose last working day has passed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        $employees = Employee::withoutGlobalScope(BusinessScope::class)
            ->where('status', EmployeeStatus::Separated)
            ->whereNotNull('separation_date')
            ->whereDate('separation_date', '<', $today->toDateString())
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->with('user')
            ->get();

        $count = 0;

        foreach ($employees as $employee) {
            if ($employee->user === null || ! $employee->user->is_active) {
                continue;
            }

            $this->line(sprintf(
                '%s (%s) left on %s',
                $employee->fullName(),
                $employee->employee_code,
                $employee->separation_date->toDateString(),
            ));

            if (! $dryRun) {
                RevokeErpAccess::for($employee->user);
            }

            $count++;
        }

        $this->info($dryRun
            ? "{$count} login(s) would be disabled."
            : "Disabled {$count} login(s) for separated employees past their last working day.");

        return self::SUCCESS;
    }
}
