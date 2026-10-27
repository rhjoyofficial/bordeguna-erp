<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AccrueMonthlyEarnedLeave;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The monthly earned-leave accrual, scheduled for the 24th and callable by
 * hand for a catch-up month.
 *
 * ⭐ 27 Oct 2026 (Joy): the month is the 24-23 payroll cycle, so the job runs
 * the day after a cycle closes and, by default, accrues the cycle that just
 * ended (on 24 Oct, month 10 = 24 Sep - 23 Oct). The default is worked out per
 * business inside the loop because the cycle start day is a business setting.
 *
 * ⚠ Unlike the year-end job this COMMITS by default. It adds days rather than
 * removing them, it is idempotent per employee and month, and a scheduler that
 * needed a flag to do its job would simply never do it. `--dry-run` is there
 * for the human who wants to look first.
 */
class AccrueEarnedLeave extends Command
{
    protected $signature = 'hrm:accrue-earned-leave
        {--year= : Year of the payroll cycle being accrued (defaults to the cycle that last ended)}
        {--month= : Payroll cycle month being accrued, labelled by where it ends (defaults to the cycle that last ended)}
        {--business= : Limit to one business id}
        {--dry-run : Show the decisions without recording them}';

    protected $description = 'Credit one month of earned leave to every permanent employee whose lateness stayed within the policy';

    public function handle(AccrueMonthlyEarnedLeave $accrue, BusinessContext $context): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            $context->set($business->id);

            // The last cycle that has fully ended: the day before the current
            // cycle began sits inside it.
            $previous = PayrollCycle::containing(PayrollCycle::containing(Carbon::today())['start']->copy()->subDay());
            $year = (int) ($this->option('year') ?: $previous['year']);
            $month = (int) ($this->option('month') ?: $previous['month']);

            [$start, $end] = PayrollCycle::forMonth($year, $month);
            $label = Carbon::create($year, $month, 1)->format('F Y')
                .' ('.$start->format('j M').' to '.$end->format('j M Y').')';
            $this->line('');
            $this->info("{$business->name} - earned leave for {$label}".($dryRun ? ' (dry run)' : ''));

            $result = $accrue->handle($year, $month, $dryRun);

            if ($result['reason'] !== null) {
                $this->warn($result['reason']);

                continue;
            }

            if ($result['rows'] !== []) {
                $this->table(
                    ['Code', 'Employee', 'Ordinary', 'Serious', 'Days', 'Withheld because'],
                    collect($result['rows'])->map(fn (array $row) => [
                        $row['employee_code'],
                        $row['employee'],
                        $row['ordinary_lates'],
                        $row['serious_lates'],
                        $row['days'],
                        $row['withheld_reason'] === null ? '' : "<fg=yellow>{$row['withheld_reason']}</>",
                    ])->all(),
                );
            }

            $this->line(sprintf(
                '%d granted (%s day(s)) · %d withheld · %d already decided',
                $result['granted'], $result['days'], $result['withheld'], $result['skipped'],
            ));
        }

        if ($dryRun) {
            $this->line('');
            $this->comment('Nothing was recorded. Re-run without --dry-run to post these decisions.');
        }

        return self::SUCCESS;
    }
}
