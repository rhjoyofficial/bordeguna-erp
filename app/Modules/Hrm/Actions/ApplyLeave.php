<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Puts an approved leave request into effect.
 *
 * Two things happen, both idempotent. The balance is charged once through a
 * keyed ledger entry — only the paid portion, since LEV-07's unpaid overflow
 * draws on nothing — and every covered day is reprocessed so the attendance
 * engine sees the leave. The engine itself is untouched: it already reads
 * `hasApprovedLeave` off the DayContext, and the pipeline now supplies it.
 */
class ApplyLeave
{
    public function __construct(private readonly ProcessAttendance $process) {}

    public function handle(LeaveRequest $request): LeaveRequest
    {
        return DB::transaction(function () use ($request) {
            if (! $request->isApplied()) {
                $request->update(['applied_at' => now()]);
            }

            $this->charge($request);
            $this->reprocessCoveredDays($request);

            return $request->fresh();
        });
    }

    private function charge(LeaveRequest $request): void
    {
        $paidDays = round((float) $request->days - (float) $request->unpaid_days, 2);

        if ($paidDays <= 0 || ! $request->leaveType?->deducts_balance) {
            return;
        }

        LeaveLedgerEntry::once("leave_request:{$request->id}", [
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'year' => (int) Carbon::parse($request->start_date)->year,
            'entry_type' => LeaveLedgerEntryType::Taken,
            'days' => -$paidDays,
            'occurred_on' => Carbon::parse($request->start_date)->toDateString(),
            'note' => $request->summaryLine(),
            'reference_type' => $request->getMorphClass(),
            'reference_id' => $request->id,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Only the days the request actually charges are reprocessed — the holidays
     * LEV-06 excluded were never part of the leave.
     */
    private function reprocessCoveredDays(LeaveRequest $request): void
    {
        $employee = $request->employee;

        if ($employee === null) {
            return;
        }

        foreach ($request->covered_dates ?? [] as $date) {
            $this->process->forDay($employee, $date, force: true);
        }
    }
}
