<?php

namespace App\Modules\Sales\Couriers\Locations;

use App\Modules\Sales\Couriers\Models\CourierLocationSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One batch of a location sync. Makes a bounded number of calls, then queues
 * the next batch; the last batch runs the matcher.
 *
 * ⚠ One batch per provider at a time (a cache lock), so pressing "Sync now"
 * twice cannot double the calls to the courier. The lock is released BEFORE
 * the next batch is queued, or that batch would find it taken and stop the
 * chain.
 */
class SyncCourierLocations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $syncId) {}

    public function handle(LocationSync $sync, CourierLocationMatcher $matcher): void
    {
        $run = CourierLocationSync::query()->find($this->syncId);

        if ($run === null || $run->status !== CourierLocationSync::RUNNING) {
            return;
        }

        $lock = Cache::lock('couriers:location-sync:'.$run->provider, $this->timeout);

        if (! $lock->get()) {
            return;
        }

        try {
            $done = $sync->step($run, max(1, (int) config('couriers.location_sync_calls_per_job', 150)));

            if ($done) {
                $matcher->run($run->provider);
            }
        } catch (Throwable $e) {
            $run->forceFill(['status' => CourierLocationSync::FAILED, 'last_error' => mb_substr('Stopped: '.$e->getMessage(), 0, 500)])->save();
            $done = true;
        } finally {
            $lock->release();
        }

        if (! $done && $run->fresh()?->status === CourierLocationSync::RUNNING) {
            self::dispatch($this->syncId);
        }
    }
}
