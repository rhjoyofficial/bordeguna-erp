<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Modules\Hrm\Support\LeaveDayCalculator;
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
        if (! $request->leaveType?->deducts_balance) {
            return;
        }

        // ⭐ 27 Sep 2026 (HRM audit): re-check the balance AT APPROVAL, not only
        // when the request was made. Another request may have drawn the balance
        // down since, so the paid portion is capped at what is available now and
        // the rest becomes unpaid - exactly the split the request itself uses.
        // Without this, two separate requests could each draw the same balance
        // and drive it negative.
        //
        // Rule #17 (approved 14 Oct 2026): a leave across New Year is charged to
        // each leave year for its own days, one ledger entry per year, each
        // capped by that year's own balance. The first year keeps the original
        // key, so a leave applied before this change is never charged again.
        ['by_year' => $byYear, 'charges' => $charges, 'unpaid' => $newUnpaid] = $this->plan($request);

        if (abs($newUnpaid - (float) $request->unpaid_days) > 0.001) {
            $request->update(['unpaid_days' => $newUnpaid]);
        }

        $firstYear = array_key_first($byYear);

        foreach ($charges as $year => $charge) {
            if ($charge <= 0) {
                continue;
            }

            $occurredOn = $year === $firstYear
                ? Carbon::parse($request->start_date)->toDateString()
                : collect($request->covered_dates ?? [])->first(fn (string $d) => (int) substr($d, 0, 4) === $year);

            LeaveLedgerEntry::once($year === $firstYear ? "leave_request:{$request->id}" : "leave_request:{$request->id}:{$year}", [
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'year' => $year,
                'entry_type' => LeaveLedgerEntryType::Taken,
                'days' => -$charge,
                'occurred_on' => $occurredOn,
                'note' => $request->summaryLine(),
                'reference_type' => $request->getMorphClass(),
                'reference_id' => $request->id,
                'created_by' => Auth::id(),
            ]);
        }
    }

    /**
     * What approving would charge, year by year, and the unpaid days that
     * leaves. ⭐ 27 Oct 2026: public so the approval card can show the pay that
     * WILL apply before anyone says yes, from this one computation.
     *
     * @return array{by_year: array<int, float>, charges: array<int, float>, unpaid: float}
     */
    public function plan(LeaveRequest $request): array
    {
        $intendedPaid = round((float) $request->days - (float) $request->unpaid_days, 2);
        $byYear = app(LeaveDayCalculator::class)->daysByYear($request->covered_dates ?? [], (float) $request->days);

        if ($byYear === []) {
            $byYear = [(int) Carbon::parse($request->start_date)->year => (float) $request->days];
        }

        if (! $request->leaveType?->deducts_balance) {
            return ['by_year' => $byYear, 'charges' => [], 'unpaid' => round((float) $request->unpaid_days, 2)];
        }

        $charges = [];
        $remaining = $intendedPaid;

        foreach ($byYear as $year => $yearDays) {
            $available = max(0.0, app(LeaveBalance::class)->for($request->employee_id, $request->leave_type_id, $year));
            $charge = round(max(0.0, min($yearDays, $available, $remaining)), 2);
            $charges[$year] = $charge;
            $remaining = round($remaining - $charge, 2);
        }

        return ['by_year' => $byYear, 'charges' => $charges, 'unpaid' => round((float) $request->days - array_sum($charges), 2)];
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

        // Rule #18 (14 Oct 2026): days in a cycle whose payroll is approved
        // stay as approved; the cycle is priced once and settled forward.
        $this->process->deferringFrozen(function () use ($request, $employee) {
            foreach ($request->covered_dates ?? [] as $date) {
                $this->process->forDay($employee, $date, force: true);
            }
        });
    }
}
