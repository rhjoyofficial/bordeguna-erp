<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\GrantAnnualLeaveQuota;
use App\Modules\Hrm\Actions\YearEndLeaveProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The scheduled year-end leave process (Corrections C6b and C6d).
 *
 * Defaults to a dry run. Committing is an explicit choice, because this is the
 * one job in the module that can remove days an employee earned — the safe
 * default is to show what would happen rather than do it.
 *
 * Both modes call the same YearEndLeaveProcessor::plan(), so a preview and a
 * commit can never disagree about the outcome. Committing is idempotent: every
 * posting is keyed, so a scheduler that fires twice charges nobody twice.
 */
class RunLeaveYearEnd extends Command
{
    protected $signature = 'hrm:leave-year-end
        {--year= : The year being closed (defaults to the previous year)}
        {--business= : Limit to one business id}
        {--commit : Actually post the carry-forward and lapse entries}
        {--grant : Also grant the next year\'s annual quota}';

    protected $description = 'Preview or commit the year-end leave carry-forward and lapse (C6b/C6d)';

    public function handle(YearEndLeaveProcessor $processor, GrantAnnualLeaveQuota $grant, BusinessContext $context): int
    {
        $year = (int) ($this->option('year') ?: Carbon::now()->subYear()->year);
        $commit = (bool) $this->option('commit');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        if ($businesses->isEmpty()) {
            $this->warn('No businesses to process.');

            return self::SUCCESS;
        }

        foreach ($businesses as $business) {
            $context->set($business->id);

            $this->line('');
            $this->info("{$business->name} — closing {$year}".($commit ? '' : ' (dry run)'));

            $run = $commit ? $processor->commit($year) : $processor->preview($year);

            $this->table(
                ['Employee', 'Leave type', 'Closing', 'Cap', 'Carry forward', 'Lapse'],
                collect($run->plan ?? [])->map(fn (array $row) => [
                    $row['employee'],
                    $row['leave_type'],
                    $row['closing_balance'],
                    $row['cap'],
                    $row['carry_forward'],
                    $row['lapse'] > 0 ? "<fg=yellow>{$row['lapse']}</>" : '0',
                ])->all(),
            );

            $this->line(sprintf(
                '%d employee(s) · %s day(s) carried · %s day(s) lapsing',
                $run->employees_affected,
                $run->total_carried,
                $run->total_lapsed,
            ));

            if ($commit && $this->option('grant')) {
                $granted = $grant->handle($year + 1);
                $this->line(sprintf('Granted %d quota entries for %d (%d already present).', $granted['granted'], $year + 1, $granted['skipped']));
            }
        }

        if (! $commit) {
            $this->line('');
            $this->comment('Nothing was changed. Re-run with --commit to post these entries.');
        }

        return self::SUCCESS;
    }
}
