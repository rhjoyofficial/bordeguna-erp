<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\FinalizeAttendance;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\AttendanceProcessingRun;
use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The nightly attendance processor (register item 2, Corrections C15a to C15e).
 *
 * Until this existed, attendance was only processed when somebody pressed a
 * button. Every attendance-derived figure was therefore as current as a
 * person's memory, and since Correction C14 that also meant TA & DA was priced
 * from whichever days happened to have been processed — silently underpaying
 * two people for every day nobody got to.
 *
 * **The window, and why it is not a single day (C15a).** Each run reprocesses
 * today plus the previous seven days, because the ZKTeco punch import is
 * manual: Tuesday's punches routinely arrive on Friday. A day is finalised only
 * once it drops out of that window, so late-arriving punches still reach a day
 * that is open to being recomputed. Finalising on the night itself would have
 * switched the self-healing off entirely, since `ProcessAttendance` skips a
 * finalised day.
 *
 * ⚠⚠ **THIS COMMAND MUST NEVER PASS `force: true`.** `ProcessAttendance::store()`
 * clears `finalized_at` on every write, so forcing would un-finalise every day
 * it touched, including days HR closed by hand. Skipping finalised days is the
 * correct behaviour, not a limitation to work around.
 *
 * ⚠⚠ **BUSINESS CONTEXT IS SET PER BUSINESS, AND THAT IS LOAD-BEARING.**
 * `BusinessScope` fails closed with `1 = 0`, and `BelongsToBusiness` fills
 * `business_id` from the same context. Without it this command reads no punches
 * and no policy, concludes everybody was absent, and then hits a not-null
 * constraint trying to store that. A missing context looks exactly like "this
 * business has no employees", which is why a test pins it. `RunLeaveYearEnd` is
 * the precedent being followed.
 */
class ProcessAttendanceDaily extends Command
{
    /**
     * Days reprocessed on each run, today inclusive. Matches the manual import
     * cadence; a day older than this is finalised rather than recomputed.
     */
    private const WINDOW_DAYS = 7;

    protected $signature = 'hrm:process-attendance
        {--from= : First date to process (defaults to 7 days before --to)}
        {--to= : Last date to process (defaults to today)}
        {--business= : Limit to one business id}
        {--dry-run : Report what would be processed without writing anything}';

    protected $description = 'Process attendance for the self-healing window and finalise days that have left it';

    public function handle(
        ProcessAttendance $process,
        FinalizeAttendance $finalize,
        BusinessContext $context,
    ): int {
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->startOfDay() : Carbon::today();
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : $to->copy()->subDays(self::WINDOW_DAYS - 1);

        if ($from->gt($to)) {
            $this->error('--from is after --to.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $trigger = $this->option('from') || $this->option('to') || $this->option('business') || $dryRun
            ? 'manual'
            : 'schedule';

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        if ($businesses->isEmpty()) {
            $this->warn('No businesses to process.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($businesses as $business) {
            $context->set($business->id);

            $this->line('');
            $this->info("{$business->name} — {$from->toDateString()} to {$to->toDateString()}".($dryRun ? ' (dry run)' : ''));

            $run = $dryRun ? null : AttendanceProcessingRun::start($from, $to, $trigger, $dryRun);
            $counts = $this->emptyCounts();

            try {
                $counts = $this->processBusiness($process, $finalize, $from, $to, $dryRun, $counts);
                $run?->complete($counts);
                $this->report($counts);
            } catch (Throwable $e) {
                // The run row is closed as failed and the loop continues: one
                // business's failure must not deprive the others of a nightly
                // process. The non-zero exit code at the end is what tells the
                // container something went wrong.
                $run?->fail($e, $counts);
                $failed++;

                $this->error(sprintf('%s failed: %s', $business->name, $e->getMessage()));
                report($e);
            }
        }

        // Leaving the context set would let whatever runs next inherit the last
        // business silently — the same failure this command guards against, in
        // the opposite direction.
        $context->forget();

        if ($dryRun) {
            $this->line('');
            $this->comment('Nothing was changed.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function processBusiness(
        ProcessAttendance $process,
        FinalizeAttendance $finalize,
        Carbon $from,
        Carbon $to,
        bool $dryRun,
        array $counts,
    ): array {
        // Oldest first. POL-03's ladders escalate on a running count and
        // `priorOccurrences()` reads rows strictly before the date being
        // processed, so processing out of order gives a day the wrong
        // occurrence number and therefore the wrong consequence.
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $lock = AttendancePeriodLock::covering($date);

            if ($lock !== null) {
                // C15e. A locked period is closed, and payroll may already have
                // consumed these figures. Recomputing one could silently
                // restate a number behind an approved run.
                $counts['days_skipped_locked'] += $this->countExistingDays($date);
                $this->line(sprintf('  %s  skipped — period locked', $date->toDateString()));

                continue;
            }

            if ($dryRun) {
                $counts['days_processed'] += $this->countExistingDays($date);

                continue;
            }

            $before = $this->countExistingDays($date);
            $processed = $process->forDate($date);

            $counts['days_processed'] += $processed->count();
            $counts['employees_seen'] = max($counts['employees_seen'], $processed->count());
            // forDate() returns only what it actually wrote, so anything that
            // existed and did not come back was skipped as already finalised.
            $counts['days_skipped_finalized'] += max(0, $before - $processed->count());
        }

        if (! $dryRun) {
            $counts['days_finalized'] = $this->finalizeExpired($finalize, $from);
        }

        return $counts;
    }

    /**
     * Close the days that have dropped out of the reprocessing window (C15a).
     *
     * ⚠ Only days *before* the window are eligible; a day inside it must stay
     * open so late punches can still reach it. `bySystem()` additionally
     * refuses anything still provisional, so an unreviewed exception is never
     * closed merely because a week went by.
     */
    private function finalizeExpired(FinalizeAttendance $finalize, Carbon $windowStart): int
    {
        $expired = DailyAttendance::query()
            ->whereDate('attendance_date', '<', $windowStart->toDateString())
            ->whereNull('finalized_at')
            ->where('is_provisional', false)
            ->where('holds_payroll', false)
            // Bounded so one run cannot walk the entire history on a database
            // that has never been processed. The next run takes the next batch.
            ->orderBy('attendance_date')
            ->limit(2000)
            ->get();

        $result = $finalize->bySystem($expired);

        if ($result['finalized'] > 0) {
            $this->line(sprintf('  finalised %d day(s) that left the window', $result['finalized']));
        }

        return $result['finalized'];
    }

    private function countExistingDays(Carbon $date): int
    {
        return DailyAttendance::query()->onDate($date)->count();
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return [
            'days_processed' => 0,
            'days_finalized' => 0,
            'days_skipped_locked' => 0,
            'days_skipped_finalized' => 0,
            'employees_seen' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function report(array $counts): void
    {
        $this->line(sprintf(
            '  %d processed · %d finalised · %d skipped (locked) · %d skipped (already finalised)',
            $counts['days_processed'],
            $counts['days_finalized'],
            $counts['days_skipped_locked'],
            $counts['days_skipped_finalized'],
        ));
    }
}
