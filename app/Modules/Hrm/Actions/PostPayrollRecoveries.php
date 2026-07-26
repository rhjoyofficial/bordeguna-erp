<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Support\PayrollBalanceLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Closes out the PAY-11 balances a disbursed payroll actually recovered
 * (Correction C8c).
 *
 * Deliberately at **disbursement**, not at calculation. A calculated run can be
 * re-run freely under WFL-05, and posting the recovery while the figures are
 * still moving would clear a debt that had not yet been taken from anybody.
 * Disbursement is the first moment the deduction is real money.
 *
 * Keyed per employee-period, so re-running this over an already-disbursed run —
 * or reprocessing a version chain — recovers nothing twice.
 */
class PostPayrollRecoveries
{
    public function handle(PayrollRun $run): int
    {
        $period = $run->period;

        if ($period === null) {
            return 0;
        }

        return DB::transaction(function () use ($run, $period) {
            $posted = 0;

            $payslips = $run->payslips()->with('lines')->get();

            foreach ($payslips as $payslip) {
                $recovered = $this->recoveredOn($payslip);

                if ($recovered <= 0) {
                    continue;
                }

                $entry = PayrollBalanceEntry::once(
                    PayrollBalanceLedger::recoveryKey($payslip->employee_id, $period->id),
                    [
                        'employee_id' => $payslip->employee_id,
                        'entry_type' => PayrollBalanceEntryType::Recovered,
                        // Negative: the debt shrinks by what was taken.
                        'amount' => -$recovered,
                        'payroll_period_id' => $period->id,
                        'occurred_on' => $period->end_date->toDateString(),
                        'note' => sprintf(
                            'Recovered %s from %s payroll (PAY-11).',
                            number_format($recovered, 2),
                            $period->label(),
                        ),
                        'reference_type' => $payslip->getMorphClass(),
                        'reference_id' => $payslip->id,
                        'created_by' => Auth::id(),
                    ],
                );

                if ($entry !== null) {
                    $posted++;
                }
            }

            return $posted;
        });
    }

    /**
     * What this payslip actually took back. Read off the stored line rather than
     * recomputed from the ledger: the payslip is the record of what was paid, and
     * a balance that has moved since must not change what was deducted.
     */
    private function recoveredOn(Payslip $payslip): float
    {
        return round((float) $payslip->lines
            ->where('code', 'balance_recovery')
            ->sum(fn (PayslipLine $line) => (float) $line->amount), 2);
    }
}
