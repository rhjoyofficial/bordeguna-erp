<?php

namespace App\Modules\Accounting\Console\Commands;

use App\Modules\Accounting\Actions\ReleaseAmortizations;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Console\Command;

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
        foreach (Business::query()->orderBy('id')->get() as $business) {
            $context->set($business->id);

            $posted = $release->through(now(), null);

            if ($posted > 0) {
                $this->info("Business {$business->id}: {$posted} monthly releases posted.");
            }
        }

        $context->forget();

        return self::SUCCESS;
    }
}
