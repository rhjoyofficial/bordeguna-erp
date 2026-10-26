<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Support\AttendanceFollowUp;
use App\Modules\Hrm\Support\EarlyLeaveRules;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/**
 * The approver's side of early leave (Joy's HRM queue, 27 Oct 2026): early
 * leave requests waiting for a decision, and the provisional days of the
 * current payroll cycle, each with its dates, each linking to HRM > Attendance
 * > Daily with the review filter over exactly those dates.
 *
 * ⚠ Provisional days turn HIGH from the warning day (the 20th for the 24-23
 * cycle): one of them blocks the whole payroll run. A request turns HIGH once
 * it has waited as long as the reminder allows.
 */
class AttendanceReviewSource extends Source
{
    /** How many dates a line spells out before saying "and N more". */
    private const DATES_SHOWN = 8;

    public function __construct(private readonly AttendanceFollowUp $followUp) {}

    public function key(): string
    {
        return 'attendance_review';
    }

    public function area(): string
    {
        return 'work';
    }

    protected function permissions(): array
    {
        return ['hrm.attendance.manage', 'hrm.leave.approve'];
    }

    public function items(User $user, int $businessId): array
    {
        $items = [];

        $waiting = Movement::query()
            ->where('business_id', $businessId)
            ->where('type', MovementType::EarlyLeave)
            ->whereNull('applied_at')
            ->whereNull('cancelled_at')
            ->whereHas('approvalRequest', fn ($query) => $query->where('status', ApprovalStatus::Pending))
            ->with('approvalRequest')
            ->orderBy('movement_date')
            ->get();

        if ($waiting->isNotEmpty()) {
            $dates = $waiting->map(fn (Movement $m) => Carbon::parse($m->movement_date)->toDateString())->unique()->values()->all();
            $since = $waiting->min(fn (Movement $m) => $m->approvalRequest?->created_at);
            $overdue = $since !== null && Carbon::parse($since)->lte(Carbon::now()->subDays(EarlyLeaveRules::reminderAfterDays()));

            $items[] = $this->item(
                'early_leave_pending',
                $overdue ? AttentionItem::HIGH : AttentionItem::NORMAL,
                'early_leave_pending',
                '{count} early leave requests are waiting for a decision: {dates}.',
                ['count' => $waiting->count(), 'dates' => self::dateList($dates)],
                ValidatePayrollRun::reviewUrl($dates),
                ['since' => $since, 'count' => $waiting->count(), 'ownerRole' => 'Manager'],
            );
        }

        [$from, $to, $end] = AttendanceFollowUp::currentWindow();
        $days = $this->followUp->provisionalDays($from, $to);

        if ($days->isNotEmpty()) {
            $dates = $days->map(fn ($day) => $day->attendance_date->toDateString())->unique()->values()->all();
            $late = Carbon::today()->gte($end->copy()->subDays(EarlyLeaveRules::cycleEndWarningDays()));

            $items[] = $this->item(
                'provisional',
                $late ? AttentionItem::HIGH : AttentionItem::NORMAL,
                'attendance_provisional',
                '{count} attendance days of this payroll cycle still await review: {dates}.',
                ['count' => $days->count(), 'dates' => self::dateList($dates)],
                ValidatePayrollRun::reviewUrl($dates),
                ['since' => $dates[0], 'count' => $days->count(), 'ownerRole' => 'Manager'],
            );
        }

        return $items;
    }

    /** @param list<string> $dates */
    private static function dateList(array $dates): string
    {
        $shown = array_map(fn (string $date) => Carbon::parse($date)->format('j M'), array_slice($dates, 0, self::DATES_SHOWN));
        $more = count($dates) - count($shown);

        return implode(', ', $shown).($more > 0 ? " and {$more} more" : '');
    }
}
