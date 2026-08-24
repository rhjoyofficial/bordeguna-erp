<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AccrueMonthlyEarnedLeave;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The monthly earned-leave accrual, scheduled for the 1st and callable by hand
 * for a catch-up month.
 *
 * ⚠ Unlike the year-end job this COMMITS by default. It adds days rather than
 * removing them, it is idempotent per employee and month, and a scheduler that
 * needed a flag to do its job would simply never do it. `--dry-run` is there
 * for the human who wants to look first.
 */
class AccrueEarnedLeave extends Command
{
    protected $signature = 'hrm:accrue-earned-leave
        {--year= : Year of the month being accrued (defaults to last month)}
        {--month= : Month being accrued (defaults to last month)}
        {--business= : Limit to one business id}
        {--dry-run : Show the decisions without recording them}';

    protected $description = 'Credit one month of earned leave to every permanent employee whose lateness stayed within the policy';

    public function handle(AccrueMonthlyEarnedLeave $accrue, BusinessContext $context): int
    {
        $previous = Carbon::now()->subMonthNoOverflow();
        $year = (int) ($this->option('year') ?: $previous->year);
        $month = (int) ($this->option('month') ?: $previous->month);
        $dryRun = (bool) $this->option('dry-run');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            $context->set($business->id);

            $label = Carbon::create($year, $month, 1)->format('F Y');
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
