<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Support\CompensatoryCredit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Puts an approved overtime assignment into effect (ADJ-01, ADJ-04).
 *
 * The two outcomes are mutually exclusive, and that is the point of the rule:
 *
 *  - **Ordinary overtime** does nothing here at all. The assignment simply
 *    becomes applied, and payroll finds it at the next calculation. Payroll
 *    prices facts; this action does not price anything.
 *  - **Holiday or off-day overtime** is compensated in leave (ADJ-04, Correction
 *    C8b), so a keyed credit is posted to the Compensatory Leave balance and the
 *    hours never reach a payslip.
 *
 * That the credit lands on the Phase-2c leave ledger, untouched, is the second
 * time Phase 3 has integrated with Leave without reopening it — the ledger's
 * append-only, keyed design turns out to accept a payroll-side event as
 * naturally as it accepted a year-end run.
 */
class ApplyOvertimeAssignment
{
    public function handle(OvertimeAssignment $assignment, ?PayrollConfig $config = null): OvertimeAssignment
    {
        $config ??= PayrollConfig::defaults();

        return DB::transaction(function () use ($assignment, $config) {
            if (! $assignment->is_holiday_work) {
                if (! $assignment->isApplied()) {
                    $assignment->update(['applied_at' => now()]);
                }

                return $assignment->fresh();
            }

            $days = CompensatoryCredit::daysFor((float) $assignment->hours, $config);

            $assignment->update([
                'applied_at' => $assignment->applied_at ?? now(),
                // Stored rather than re-derived: the standard-hours divisor is
                // configuration and may change, but what someone was granted
                // must not move underneath them.
                'compensatory_days' => $days,
            ]);

            $this->credit($assignment, $days, $config);

            return $assignment->fresh();
        });
    }

    /**
     * Correction C8b sends the credit to a dedicated Compensatory Leave type
     * that neither carries forward nor encashes — so holiday work buys time off,
     * not a cash liability the company must eventually honour. ADJ-04 declining
     * every pay multiple is what makes that the right reading.
     */
    private function credit(OvertimeAssignment $assignment, float $days, PayrollConfig $config): void
    {
        if ($days <= 0) {
            return;
        }

        $type = LeaveType::where('code', LeaveType::COMPENSATORY)->first();

        if ($type === null) {
            return;
        }

        LeaveLedgerEntry::once("overtime_assignment:{$assignment->id}", [
            'employee_id' => $assignment->employee_id,
            'leave_type_id' => $type->id,
            'year' => (int) Carbon::parse($assignment->work_date)->year,
            'entry_type' => LeaveLedgerEntryType::CompensatoryCredit,
            'days' => $days,
            'occurred_on' => Carbon::parse($assignment->work_date)->toDateString(),
            'note' => CompensatoryCredit::explain((float) $assignment->hours, $days, $config),
            'reference_type' => $assignment->getMorphClass(),
            'reference_id' => $assignment->id,
            'created_by' => Auth::id(),
        ]);
    }
}
