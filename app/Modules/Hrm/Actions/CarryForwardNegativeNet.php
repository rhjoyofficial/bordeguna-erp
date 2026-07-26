<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Support\PayrollBalanceLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PAY-11's manual resolution, per Correction C8c.
 *
 * PAY-11 ticked three things — an automatic approval block, a manual resolution,
 * and a carry-forward balance — but never said how the balance is recovered.
 * C8c settled it: the shortfall carries forward and the next payroll recovers it
 * in full; if that month also falls short, it simply rolls again. No invented
 * installment schedule, no invented cap.
 *
 * This is the human decision, and it does two things and no more. It records the
 * shortfall on the balance ledger, and it stops there. It does not touch the
 * payslip — the engine remains the only author of a payslip figure, so the way
 * this month's net reaches zero is by recalculating with the credit as an
 * ordinary input, exactly as a bonus or an adjustment would arrive.
 *
 * Keyed on employee and period rather than on the payslip, because WFL-05 lets a
 * Calculated run be re-run freely and each re-run replaces its payslips. The
 * decision outlives the row that prompted it.
 */
class CarryForwardNegativeNet
{
    /**
     * @throws ValidationException
     */
    public function handle(Payslip $payslip, ?string $note = null): PayrollBalanceEntry
    {
        $run = $payslip->run;
        $period = $run?->period;

        if ($run === null || $period === null) {
            throw ValidationException::withMessages(['payslip' => 'This payslip is not attached to a payroll run.']);
        }

        if (! $run->status->isRerunnable()) {
            // Past Calculated the figures are on their way to being paid, and
            // WFL-06 sends a correction to the next month instead.
            throw ValidationException::withMessages([
                'payslip' => sprintf(
                    'This payroll is %s. A shortfall can only be carried forward while the run is still Draft or Calculated (WFL-05).',
                    $run->status->label(),
                ),
            ]);
        }

        $shortfall = round(-1 * (float) $payslip->net, 2);

        if ($shortfall <= 0) {
            throw ValidationException::withMessages([
                'payslip' => 'This payslip does not have a negative net, so there is nothing to carry forward.',
            ]);
        }

        return DB::transaction(function () use ($payslip, $period, $shortfall, $note) {
            $existing = PayrollBalanceEntry::withoutGlobalScopes()
                ->where('source_key', PayrollBalanceLedger::carryForwardKey($payslip->employee_id, $period->id))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return PayrollBalanceEntry::create([
                'employee_id' => $payslip->employee_id,
                'entry_type' => PayrollBalanceEntryType::CarriedForward,
                // Positive: the employee owes this to the company.
                'amount' => $shortfall,
                'payroll_period_id' => $period->id,
                'occurred_on' => $period->end_date->toDateString(),
                'note' => $note ?? sprintf(
                    '%s net was short by %s; carried forward for recovery (PAY-11).',
                    $period->label(),
                    number_format($shortfall, 2),
                ),
                'reference_type' => $payslip->getMorphClass(),
                'reference_id' => $payslip->id,
                'source_key' => PayrollBalanceLedger::carryForwardKey($payslip->employee_id, $period->id),
                'created_by' => Auth::id(),
            ]);
        });
    }
}
