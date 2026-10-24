<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Hrm\Corrections\FrozenPayrollCheck;
use App\Modules\Hrm\Corrections\HrCorrectionRequests;
use App\Modules\Hrm\Corrections\HrRecordCorrector;
use App\Modules\Hrm\Corrections\LeaveRequestActionPolicy;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Support\FrozenAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ Cancel an approved leave (corrections wave 3, 24 Oct 2026; FINAL-PLAN 3.4:
 * "leave days given back, attendance re-judged. If that month's payroll is
 * approved, the difference goes to next month").
 *
 * In one transaction, all or nothing:
 *   1. lock and re-read the leave; ask the policy again (only HR, only an
 *      approved leave in effect, not a closed leave year or a locked
 *      attendance period). A double click finds it cancelled and is refused.
 *   2. payroll approved for a covered day: nothing changes yet. A request for a
 *      second person is raised instead (HR plus one other approver).
 *   3. otherwise, or once the second person approves: mark it Cancelled (the
 *      row stays, with who, when and why); give the days back with one
 *      `cancelled` ledger row per `taken` row, dated like the row it answers
 *      (the original date, FINAL-PLAN section 9 answer 4); judge every covered
 *      day again without the leave.
 *
 * ⛔ Locked payroll is never changed: a frozen day is not re-judged, its
 * difference is priced onto the next open payroll (rule #18, inside
 * ProcessAttendance). A future day keeps no row of its own: it is judged when
 * it comes, exactly as before the leave was approved.
 *
 * ⚠ Invariant, checked before the commit: the ledger rows of this leave sum to
 * zero. Anything else rolls the whole cancellation back.
 */
class CancelLeaveRequest
{
    public function __construct(
        private readonly LeaveRequestActionPolicy $policy,
        private readonly HrCorrectionRequests $requests,
        private readonly ProcessAttendance $process,
    ) {}

    /**
     * @return array{outcome: 'cancelled'|'requested', request: ?HrCorrectionRequest}
     */
    public function handle(LeaveRequest $leave, User $by, ActionReason $reason, ?HrCorrectionRequest $approved = null, ?User $approver = null): array
    {
        return DB::transaction(function () use ($leave, $by, $reason, $approved, $approver) {
            $current = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();
            $current->load(['approvalRequest', 'employee', 'leaveType']);

            if ($approved !== null) {
                $this->requests->claim($approved, $current, HrRecordCorrector::fingerprintOf($current));
            }

            $availability = $this->policy->availability($current, $by, DocumentAction::Cancel);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            $number = $this->policy->numberOf($current);

            if ($approved === null && $availability->approval === ApprovalRoute::SecondPerson) {
                $frozen = Carbon::parse(FrozenPayrollCheck::frozenDate($current->covered_dates ?? []))->format('d/m/Y');

                return ['outcome' => 'requested', 'request' => $this->requests->ask(
                    HrCorrectionRequest::CANCEL_LEAVE,
                    $current,
                    $this->label($current),
                    $by,
                    $reason,
                    "Payroll for {$frozen} is already approved, so the difference goes to the next open payroll.",
                    HrRecordCorrector::fingerprintOf($current),
                )];
            }

            $current->update([
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason->display(),
            ]);

            $givenBack = $this->giveDaysBack($current, $by);
            $this->judgeAgain($current);
            $this->assertLedgerNetsToZero($current);

            CorrectionLog::event('cancelled', $by, $current, array_filter([
                'number' => $number,
                'reason' => $reason->display(),
                'days_given_back' => $givenBack,
                'approved_by' => $approver?->name,
            ], fn ($value) => $value !== null));

            if ($approved !== null && $approver !== null) {
                $this->requests->markExecuted($approved, $approver);
            }

            return ['outcome' => 'cancelled', 'request' => null];
        });
    }

    /** "Leave #12 of Karim Ahmed, Casual Leave, 2 days (06/10/2026 to 07/10/2026)". */
    public function label(LeaveRequest $leave): string
    {
        $days = rtrim(rtrim(number_format((float) $leave->days, 2), '0'), '.');

        return sprintf(
            '%s, %s, %s day%s (%s to %s)',
            $this->policy->numberOf($leave),
            $leave->leaveType?->name ?? 'leave',
            $days,
            (float) $leave->days === 1.0 ? '' : 's',
            Carbon::parse($leave->start_date)->format('d/m/Y'),
            Carbon::parse($leave->end_date)->format('d/m/Y'),
        );
    }

    private function giveDaysBack(LeaveRequest $leave, User $by): float
    {
        $taken = LeaveLedgerEntry::query()
            ->where('reference_type', $leave->getMorphClass())
            ->where('reference_id', $leave->id)
            ->where('entry_type', LeaveLedgerEntryType::Taken)
            ->orderBy('id')
            ->get();

        $total = 0.0;

        foreach ($taken as $entry) {
            $days = round(abs((float) $entry->days), 2);

            if ($days <= 0) {
                continue;
            }

            LeaveLedgerEntry::once("leave_cancel:{$entry->id}", [
                'employee_id' => $entry->employee_id,
                'leave_type_id' => $entry->leave_type_id,
                'year' => $entry->year,
                'entry_type' => LeaveLedgerEntryType::Cancelled,
                'days' => $days,
                'occurred_on' => Carbon::parse($entry->occurred_on)->toDateString(),
                'note' => 'Cancelled: '.$leave->summaryLine(),
                'reference_type' => $leave->getMorphClass(),
                'reference_id' => $leave->id,
                'created_by' => $by->id,
            ]);

            $total += $days;
        }

        return round($total, 2);
    }

    private function judgeAgain(LeaveRequest $leave): void
    {
        $employee = $leave->employee;

        if ($employee === null) {
            return;
        }

        $today = Carbon::today()->toDateString();

        $this->process->deferringFrozen(function () use ($leave, $employee, $today) {
            foreach ($leave->covered_dates ?? [] as $date) {
                if ($date > $today && FrozenAttendance::runCovering($date) === null) {
                    // Approving judged the future day early; nobody else would
                    // have. Without the leave it has no evidence yet.
                    DailyAttendance::query()->where('employee_id', $employee->id)->whereDate('attendance_date', $date)->delete();

                    continue;
                }

                $this->process->forDay($employee, $date, force: true);
            }
        });
    }

    private function assertLedgerNetsToZero(LeaveRequest $leave): void
    {
        $net = (float) LeaveLedgerEntry::query()
            ->where('reference_type', $leave->getMorphClass())
            ->where('reference_id', $leave->id)
            ->sum('days');

        if (abs($net) > 0.001) {
            throw new RuntimeException("The leave ledger of {$this->policy->numberOf($leave)} does not net to zero after the cancellation, so nothing was changed.");
        }
    }
}
