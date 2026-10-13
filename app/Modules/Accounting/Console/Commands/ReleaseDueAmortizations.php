<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Actions\ReleaseAmortizations;
use App\Modules\Accounting\Models\AmortizationSchedule;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * The monthly release, without anybody remembering (Module 2, Slice 4).
 *
 * Runs on the 1st for every business. Each schedule posts AS ITS OWN CREATOR
 * (the action resolves that) - honest attribution, and no system user to
 * invent. A schedule whose creator is gone is skipped silently by the action;
 * the screen shows it stuck at its last released month, which is the visible
 * signal to reassign it.
 */
class ReleaseDueAmortizations extends Command
{
    protected $signature = 'accounting:release-amortizations';

    protected $description = 'Post the amortization months that have fallen due, for every business';

    public function handle(ReleaseAmortizations $release, BusinessContext $context): int
    {
        $failed = 0;

        // ⚠⚠ A failure is reported and the run goes on: one schedule refused
        // (a closed month, a head re-mapped since it was set up) used to abort
        // the whole command, so every later schedule and business silently
        // missed its month. The failed one stays at its last released month on
        // the screen and is retried the next run.
        $onFailure = function (AmortizationSchedule $schedule, Throwable $e) use (&$failed): void {
            $failed++;
            report($e);
            $this->error("Business {$schedule->business_id}, schedule {$schedule->id} ({$schedule->name}): {$e->getMessage()}");
        };

        foreach (Business::query()->orderBy('id')->get() as $business) {
            $context->set($business->id);

            try {
                $posted = $release->through(now(), null, $onFailure);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("Business {$business->id}: {$e->getMessage()}");

                continue;
            }

            if ($posted > 0) {
                $this->info("Business {$business->id}: {$posted} monthly releases posted.");
            }
        }

        $context->forget();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
