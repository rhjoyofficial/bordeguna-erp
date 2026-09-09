<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use App\Modules\Hrm\Attendance\Policy\GraceWindow;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\LatenessReviewAlert;
use App\Modules\Hrm\Notifications\LatenessReviewNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rings HR's bell when an employee uses the LAST FREE occurrence of a lateness
 * window in a month - the next one carries the ladder's consequence, so this
 * is exactly when a show cause review is worth having (Joy, 24 Aug 2026:
 * "on the 4th day a notification will send to HR").
 *
 * ⚠ The trigger is DERIVED from the ladder, never hardcoded: the alert fires
 * at (first consequence-bearing tier's from_occurrence - 1), so revising the
 * policy from "free 4 times" to "free 6 times" moves the alert with it and no
 * code changes. "Consequence-bearing" means any tier that is not Excused -
 * a warning tier is a consequence worth warning ahead of too, not only money
 * and disciplinary ones. A window whose ladder starts biting at the 1st
 * occurrence has no free run to warn about, and a window with no consequence
 * tier at all never alerts.
 *
 * ⚠ Idempotent per employee + window + calendar month through
 * lateness_review_alerts' unique index - a durable table, NOT a flag on the
 * daily row, because attendance is derived and this project force-rebuilt a
 * whole month twice in one day. An alert that re-fires on every rebuild
 * teaches HR to ignore the bell.
 */
class LatenessAlerts
{
    public function maybeAlert(DailyAttendance $day, ?AttendancePolicy $policy): void
    {
        if ($policy === null || $day->grace_window_key === null || ! $day->occurrence_number) {
            return;
        }

        $window = collect($policy->toConfig()->graceWindows)
            ->first(fn (GraceWindow $w) => $w->key === $day->grace_window_key);

        if ($window === null) {
            return;
        }

        $threshold = collect($window->tiers)
            ->filter(fn ($tier) => $tier->consequence !== AttendanceConsequence::None)
            ->min(fn ($tier) => $tier->fromOccurrence);

        // No consequence tier, or a ladder with no free occurrences to use up.
        if ($threshold === null || $threshold <= 1) {
            return;
        }

        if ($day->occurrence_number !== $threshold - 1) {
            return;
        }

        $date = Carbon::parse($day->attendance_date);

        // ⚠⚠ LABELLED BY THE PAYROLL CYCLE, BECAUSE THE LADDER IS.
        // The unique index behind this row means "the bell rings once per
        // month". Once the ladder resets on the 24th (see
        // DailyAttendance::priorOccurrences) a cycle spans two calendar
        // months, so a calendar label would let two consecutive cycles both
        // land on "September" — and the second bell would be swallowed by the
        // index in silence, which is the one failure this alert cannot have.
        $cycle = PayrollCycle::containing($date);

        try {
            $alert = LatenessReviewAlert::create([
                'business_id' => $day->business_id,
                'employee_id' => $day->employee_id,
                'grace_window_key' => $day->grace_window_key,
                'year' => $cycle['year'],
                'month' => $cycle['month'],
                'occurrence_number' => $day->occurrence_number,
                'alerted_at' => now(),
            ]);
        } catch (QueryException) {
            // The unique index says this month's bell already rang - a rebuild
            // or a parallel worker got here first. Silence is the point.
            return;
        }

        $this->notifyManagers($alert, $day, $window, $date);
    }

    private function notifyManagers(LatenessReviewAlert $alert, DailyAttendance $day, GraceWindow $window, Carbon $date): void
    {
        // Team context follows the day's business, not whatever the caller had
        // - the nightly command loops businesses and this must not leak across.
        app(PermissionRegistrar::class)->setPermissionsTeamId($day->business_id);

        // ⚠ Permission says who MAY act; the preference says who should be
        // told. Joy, 24 Aug 2026: he and Musa run HR, and Moin - who holds
        // every permission because he is the boss - is not to be disturbed by
        // its daily traffic.
        $recipients = User::permission('hrm.attendance.manage')
            ->where('is_active', true)
            ->where('receives_hr_notifications', true)
            ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $day->business_id))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $employee = $day->employee()->first();

        $summary = sprintf(
            '%s has used %d free "%s" arrivals in %s - the next one carries a consequence. Review and consider a show cause notice.',
            $employee?->fullName() ?? 'An employee',
            $alert->occurrence_number,
            $window->label,
            $date->format('F Y'),
        );

        Notification::send($recipients, new LatenessReviewNotification(
            $summary,
            route('hrm.attendance.daily.index', ['on' => $date->toDateString()]),
        ));
    }
}
