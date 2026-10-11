<?php

namespace App\Providers;

use App\Support\Dashboards\CourierSignals;
use App\Support\Realtime\RealtimeEvents;
use App\Support\Realtime\RecordChangeBuffer;
use Illuminate\Support\ServiceProvider;

/**
 * Realtime everywhere (Joy, 11 Oct 2026): record changes broadcast to every
 * open page that shows them, and courier happenings reach the dashboards.
 * docs/REALTIME-EVERYWHERE-PLAN.md.
 */
class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reset per request and per queue job: "once per (kind, id)" must never
        // outlive the unit of work it describes.
        $this->app->scoped(RecordChangeBuffer::class);
    }

    public function boot(): void
    {
        RealtimeEvents::register();
        CourierSignals::register();
    }
}
