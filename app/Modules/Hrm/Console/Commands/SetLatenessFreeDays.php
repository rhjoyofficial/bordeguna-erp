<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ⭐ 27 Sep 2026 (Joy): change how many late arrivals in a band are free before
 * a deduction, and (optionally) re-run the current payroll cycle so the change
 * takes effect on days already worked.
 *
 * The attendance policy screen (HRM → Attendance policy) is the everyday way to
 * change this going forward: a revision opens a NEW version from a date, and
 * each day is judged by the version in force that day. That is correct history,
 * but it cannot reach back into a cycle already under way. This command is the
 * deliberate back-dating tool: it rewrites the band on EVERY version of a
 * business's policy, so the whole current cycle uses the new allowance, and
 * then reprocesses the cycle. Use it only when back-dating is intended (Joy
 * authorised it on 27 Sep 2026 for the first band: 4 free, deduct on the 5th);
 * for an ordinary forward change, use the screen instead.
 *
 * ⚠ Reprocessing recomputes days from punches; a finalised day is set
 * provisional again and must be re-finalised before the pay run, which is the
 * point — its consequence has changed.
 */
class SetLatenessFreeDays extends Command
{
    protected $signature = 'hrm:set-lateness-free-days
        {days : free late days in the band before a deduction}
        {--band=slightly_late : the grace-window key to change}
        {--business= : limit to one business id (default: all)}
        {--reprocess-cycle : re-run the current payroll cycle after the change}
        {--reprocess-cycle-of= : re-run the payroll cycle that CONTAINS this date (Y-m-d), for back-dating a past cycle}
        {--dry-run : show what would change without saving}';

    protected $description = 'Set the free-day allowance for a lateness band across all policy versions, and optionally reprocess the current cycle';

    public function handle(BusinessContext $context): int
    {
        $days = (int) $this->argument('days');
        $band = (string) $this->option('band');
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 0) {
            $this->error('Free days cannot be negative.');

            return self::FAILURE;
        }

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            $context->set($business->id);

            $policies = AttendancePolicy::withoutGlobalScopes()
                ->where('business_id', $business->id)->get();

            $changed = 0;

            foreach ($policies as $policy) {
                $config = $policy->config;
                $windows = $config['grace_windows'] ?? [];
                $hit = false;

                foreach ($windows as $i => $window) {
                    if (($window['key'] ?? null) !== $band) {
                        continue;
                    }

                    // The whole point of the band: `days` free, then a deduction.
                    $windows[$i]['tiers'] = [
                        ['from_occurrence' => 1, 'consequence' => 'none'],
                        ['from_occurrence' => $days + 1, 'consequence' => 'deduction'],
                    ];
                    $hit = true;
                }

                if (! $hit) {
                    continue;
                }

                $config['grace_windows'] = $windows;

                if (! $dryRun) {
                    $policy->update(['config' => $config]);
                }

                $changed++;
            }

            $this->line(sprintf(
                '%s: %d policy version(s) set to %d free in "%s".',
                $business->name, $changed, $days, $band,
            ));

            $cycleOf = $this->option('reprocess-cycle-of');

            if (($this->option('reprocess-cycle') || $cycleOf !== null) && $changed > 0 && ! $dryRun) {
                $this->reprocessCycle($business->id, $cycleOf === null ? Carbon::today() : Carbon::parse($cycleOf));
            }
        }

        $this->info($dryRun ? 'Dry run: nothing was saved.' : 'Done.');

        return self::SUCCESS;
    }

    private function reprocessCycle(int $businessId, Carbon $withinCycle): void
    {
        $cycle = PayrollCycle::containing($withinCycle);
        $from = $cycle['start'];
        $today = Carbon::today();
        $to = $cycle['end']->greaterThan($today) ? $today : $cycle['end'];

        $process = app(ProcessAttendance::class);

        $employees = Employee::whereHas('assignments', fn ($q) => $q
            ->activeDuring($from, $to)->where('is_primary', true))
            ->orderBy('employee_code')->get();

        foreach ($employees as $employee) {
            $process->forRange($employee, $from, $to, force: true);
        }

        $this->line(sprintf(
            '  Reprocessed %s to %s for %d employee(s).',
            $from->toDateString(), $to->toDateString(), $employees->count(),
        ));
    }
}
