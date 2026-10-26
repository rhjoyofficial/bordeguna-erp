<?php

namespace App\Modules\Hrm\Console\Commands;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Notifications\AttendanceReminderNotification;
use App\Modules\Hrm\Support\AttendanceFollowUp;
use App\Modules\Hrm\Support\EarlyLeaveRules;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The two attendance reminders of Joy's HRM queue (27 Oct 2026).
 *
 *   1. An early leave request that has waited EarlyLeaveRules::reminderAfterDays()
 *      (2) reminds the approvers of its current stage, through the same
 *      NotifyStageApprovers that told them in the first place. Once per
 *      request: `movements.reminded_at` remembers it.
 *   2. EarlyLeaveRules::cycleEndWarningDays() (3) before the payroll cycle
 *      ends (the 20th for the 24-23 cycle), HR is sent the provisional days of
 *      the cycle still open, with their dates and people. One provisional day
 *      blocks the whole payroll run, so this is the last calm moment to settle
 *      them.
 *
 * ⚠ It reminds and nothing else: no day is finalised and no request decided.
 *
 * ⚠⚠ Tenant-scoped per business, entered BEFORE any user is loaded (Spatie
 * caches roles per team on the model).
 */
class SendAttendanceReminders extends Command
{
    protected $signature = 'hrm:attendance-reminders
        {--business= : Limit to one business id}
        {--dry-run : Show who would be told, and tell nobody}';

    protected $description = 'Remind approvers of early leave requests waiting too long, and HR of provisional days before the payroll cycle ends';

    public function handle(NotifyStageApprovers $notify, AttendanceFollowUp $followUp): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $businesses = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            TenantScope::enter($business->id);

            $this->remindWaitingRequests($notify, $dryRun);
            $this->warnBeforeCycleEnds($followUp, $business->id, $dryRun);
        }

        TenantScope::leave();

        if ($dryRun) {
            $this->info('Dry run. Nobody was told.');
        }

        return self::SUCCESS;
    }

    private function remindWaitingRequests(NotifyStageApprovers $notify, bool $dryRun): void
    {
        $days = EarlyLeaveRules::reminderAfterDays();

        $waiting = Movement::with(['employee', 'approvalRequest.workflow.stages'])
            ->where('type', MovementType::EarlyLeave)
            ->whereNull('applied_at')
            ->whereNull('cancelled_at')
            ->whereNull('reminded_at')
            ->whereHas('approvalRequest', fn ($query) => $query
                ->where('status', ApprovalStatus::Pending)
                ->where('created_at', '<=', Carbon::now()->subDays($days)))
            ->orderBy('id')
            ->get();

        foreach ($waiting as $movement) {
            $this->line("Reminder: {$movement->summaryLine()}");

            if ($dryRun) {
                continue;
            }

            $notify->handle(
                $movement->approvalRequest,
                sprintf('Reminder, waiting %d days: %s', $days, $movement->summaryLine()),
            );

            $movement->forceFill(['reminded_at' => now()])->save();
        }
    }

    private function warnBeforeCycleEnds(AttendanceFollowUp $followUp, int $businessId, bool $dryRun): void
    {
        $today = Carbon::today();
        $cycle = PayrollCycle::containing($today);

        if (! $today->isSameDay($cycle['end']->copy()->subDays(EarlyLeaveRules::cycleEndWarningDays()))) {
            return;
        }

        $days = $followUp->provisionalDays($cycle['start'], $today);

        if ($days->isEmpty()) {
            return;
        }

        $listed = $days->take(12)->map(fn (DailyAttendance $day) => sprintf(
            '%s (%s)',
            $day->attendance_date->format('j M'),
            $day->employee?->fullName() ?? 'employee #'.$day->employee_id,
        ))->implode(', ');
        $more = $days->count() > 12 ? sprintf(' and %d more', $days->count() - 12) : '';

        $summary = sprintf(
            '%d attendance day%s of this payroll cycle %s still provisional, %d days before the cycle ends on %s. Each one blocks the payroll run: %s%s.',
            $days->count(),
            $days->count() === 1 ? '' : 's',
            $days->count() === 1 ? 'is' : 'are',
            EarlyLeaveRules::cycleEndWarningDays(),
            $cycle['end']->format('j M Y'),
            $listed,
            $more,
        );

        $this->line($summary);

        // Permission says who MAY settle a day; the preference says who should
        // be told (LatenessAlerts, Joy 24 Aug 2026).
        $recipients = User::permission('hrm.attendance.manage')
            ->where('is_active', true)
            ->where('receives_hr_notifications', true)
            ->whereHas('businesses', fn ($query) => $query->where('businesses.id', $businessId))
            ->get();

        if ($dryRun || $recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AttendanceReminderNotification(
            $summary,
            ValidatePayrollRun::reviewUrl($days->map(fn (DailyAttendance $day) => $day->attendance_date->toDateString())->all()),
        ));
    }
}
