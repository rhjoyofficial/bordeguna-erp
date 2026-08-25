<?php

use App\Modules\Crm\Console\Commands\NotifyFollowUpsDue;
use App\Modules\Hrm\Console\Commands\AccrueEarnedLeave;
use App\Modules\Hrm\Console\Commands\ProcessAttendanceDaily;
use App\Modules\Inventory\Actions\ProposeExpiredStockWriteOffs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| ⚠ THIS FILE IS ONLY HALF THE SCHEDULER. Laravel needs something to invoke
| the schedule, and the production VPS is a pure Docker host with NO PHP on
| bare metal — so there is no host cron to add a line to. The other half is a
| dedicated `php artisan schedule:work` container in the Compose stack, kept
| SEPARATE from the queue worker: a stuck queue job must not be able to stop
| attendance from being processed.
|
| A schedule entry with no runner is silence, not an error, which is exactly
| the class of failure this slice exists to fix. `attendance_processing_runs`
| is what makes it visible (C15d) — an empty table means nothing is invoking
| this, and that is a question worth being able to ask.
|
*/

// Nightly attendance processing (register item 2, Corrections C15a–C15e).
//
// 23:00 is comfortably after both shifts end at 18:00. The exact time matters
// less than it looks: the punch import is manual, so the current day is usually
// incomplete whenever this runs, and the seven-day reprocessing window inside
// the command is what actually makes the data correct.
//
// `app.timezone` is Asia/Dhaka, so this is 23:00 Dhaka with no further
// configuration. ⚠ Do not "fix" this by hardcoding a UTC offset — the config
// default was deliberately changed to Dhaka for exactly this reason (C12).
//
// `withoutOverlapping` because a slow run must not meet the next one and
// process the same employee-day from two directions at once.
Schedule::command(ProcessAttendanceDaily::class)
    ->dailyAt('23:00')
    ->withoutOverlapping()
    ->runInBackground();

// The monthly earned-leave accrual (Joy, 24 Aug 2026). Runs on the 1st for the
// month that just ended, at 01:00 - comfortably after the 23:00 attendance
// processing has settled the final day of that month, because the lateness
// counts it reads are exactly what that run produces.
//
// ⚠ It COMMITS rather than previewing. The job adds days, it is idempotent per
// employee and month, and a scheduled job that needs a flag to act is a job
// that never acts.
Schedule::command(AccrueEarnedLeave::class)
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->runInBackground();

// ⚠ Proposes only. Expired stock is already unsellable the moment its date
// passes (StockBatch::scopeConsumable), so a customer is protected whether or
// not this ever runs - this raises the write-off a human then approves.
Schedule::call(fn () => app(ProposeExpiredStockWriteOffs::class)->handle())
    ->dailyAt('23:30')
    ->name('inventory:propose-expired-write-offs')
    ->withoutOverlapping();

// The CRM follow-up digest (25 Aug 2026). Tasks carried a due date from the
// first day and nothing ever read one: the queue had to be opened by somebody
// who already remembered to open it.
//
// ⚠ 08:30 Dhaka, before the shop opens rather than at midnight, so the message
// is waiting at the start of the working day instead of ageing overnight.
Schedule::command(NotifyFollowUpsDue::class)
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();
