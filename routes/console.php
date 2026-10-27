<?php

use App\Modules\Accounting\Console\Commands\ReleaseDueAmortizations;
use App\Modules\Assets\Console\Commands\RemindAccountRenewals;
use App\Modules\Assets\Console\Commands\RemindAssetMaintenance;
use App\Modules\Commerce\Console\Commands\FollowGatewayRefunds;
use App\Modules\Commerce\Console\Commands\SettleOnlinePayments;
use App\Modules\Crm\Console\Commands\NotifyFollowUpsDue;
use App\Modules\Crm\Console\Commands\SweepDormantCustomersCommand;
use App\Modules\Foundation\Console\Commands\SendMorningBriefing;
use App\Modules\Hrm\Console\Commands\DisableDueSeparations;
use App\Modules\Hrm\Console\Commands\ProcessAttendanceDaily;
use App\Modules\Hrm\Console\Commands\SendAttendanceReminders;
use App\Modules\Inventory\Actions\ProposeExpiredStockWriteOffs;
use App\Modules\Purchasing\Console\Commands\RollAndRemindRecurringExpenses;
use App\Modules\Sales\Couriers\Console\SyncCourierParcelsCommand;
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

// ⭐ 27 Sep 2026 (Joy): access ends on the last working day. A resignation
// dated today or in the future keeps the login live; this switches it off the
// morning after the last day has passed. 00:30 Dhaka, early enough that a
// person who left yesterday cannot sign in today.
Schedule::command(DisableDueSeparations::class)
    ->dailyAt('00:30')
    ->withoutOverlapping();

// Prepaids, accruals and deferred income release their month (Module 2,
// Slice 4). Idempotent by the released_through pointer; each schedule posts
// as its own creator.
Schedule::command(ReleaseDueAmortizations::class)
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->runInBackground();

// ⭐ 27 Oct 2026 (Joy): the earned-leave accrual is NO LONGER scheduled. "main
// part is max time i will import the attendence end of the month so no after i
// data update will be caculated when payroll runs." The 24th came before the
// month's attendance was in, so the month is now decided when its payroll is
// calculated (CalculatePayroll). `hrm:accrue-earned-leave` stays for a dry run
// or a month with no payroll.

// ⚠ Proposes only. Expired stock is already unsellable the moment its date
// passes (StockBatch::scopeConsumable), so a customer is protected whether or
// not this ever runs - this raises the write-off a human then approves.
// One nudge per sleeping customer, per active cadence - never a second
// while the first stays open.
// The morning briefing to everyone who reads money.
Schedule::command(SendMorningBriefing::class)
    ->dailyAt('07:00')
    ->onOneServer();

Schedule::command(SweepDormantCustomersCommand::class)
    ->dailyAt('07:30')
    ->onOneServer();

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

// Standing costs (Joy, 1 September 2026). Rolls every schedule forward and tells
// whoever can pay them what is about to be due.
//
// ⚠ 08:30 Dhaka, alongside the CRM digest and for the same reason: the message
// waits at the start of the working day rather than ageing overnight.
//
// ⛔ It generates REMINDERS and nothing else. No voucher, no journal, no money -
// an expense voucher here is paid the moment it exists, so a job that made one
// would record a payment nobody had made, every month, for ever.
Schedule::command(RollAndRemindRecurringExpenses::class)
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

// Asset maintenance (slice A4, 17 September 2026): plans due, faults open,
// warranties ending, to whoever keeps the register.
//
// ⚠ 08:30 Dhaka with the other digests. It reminds and nothing else.
Schedule::command(RemindAssetMaintenance::class)
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

// Attendance reminders (Joy's HRM queue, 27 October 2026): an early leave
// request waiting two days reminds its approvers once, and three days before
// the payroll cycle ends (the 20th) HR hears which days are still provisional.
//
// ⚠ 08:30 Dhaka with the other digests. It reminds and nothing else.
Schedule::command(SendAttendanceReminders::class)
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

// Account renewals (asset register slice 4, 13 October 2026): the owner and
// the hub's managers hear at 30 days, 7 days and overdue, once each.
Schedule::command(RemindAccountRenewals::class)
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

// Online payments (SSLCommerz slice P2c, 14 September 2026). Books any payment
// the gateway proved that could not be booked at the time, and cancels online
// orders still unpaid 24 hours after they were placed (Joy, 13 September).
//
// ⚠ Every ten minutes, because the first half is money a customer has already
// paid that is not yet in the books; an hour is too long for that to wait.
Schedule::command(SettleOnlinePayments::class)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Refunds sent through SSLCommerz (slice P4, 16 September 2026): asked about
// until SSLCommerz says they are finished, then booked.
//
// ⚠ Every thirty minutes: a refund takes SSLCommerz days, and the money is
// already reserved on its order, so nothing waits on this but the books.
Schedule::command(FollowGatewayRefunds::class)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ⭐ Courier parcels (courier hub slice 4, 11 Oct 2026): ask the couriers where
// open parcels are, beside their webhooks, and settle CarryBee bookings whose
// answer was lost by looking our reference up. Read-only at the courier, never
// money: a delivered parcel still waits for a person to confirm it.
Schedule::command(SyncCourierParcelsCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
