<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Splits a disbursed run into one batch per payment method (DIS-01).
 *
 * "Cash এবং bank আলাদা" is the requirement, and this is where it becomes
 * structural rather than a convention someone has to remember: the method lives
 * on the batch, so a run produces a cash batch, a bank batch and an MFS batch
 * that cannot be mixed. Everything downstream — the register export, the whole
 * batch confirmation, the bank advice attachment — then has exactly one method
 * to deal with.
 *
 * Blocked payslips are excluded. PAY-09 and PAY-11 hold those lines precisely so
 * nobody pays them, and WFL-03 will not let a run with any of them reach
 * approval in the first place; excluding them here is the belt to that brace.
 */
class CreateDisbursementBatches
{
    /**
     * @return Collection<int, DisbursementBatch>
     *
     * @throws ValidationException
     */
    public function handle(PayrollRun $run, User $user): Collection
    {
        if (! in_array($run->status, [PayrollStatus::Disbursed, PayrollStatus::Locked], true)) {
            throw ValidationException::withMessages([
                'run' => 'Batches are created once the payroll is disbursed — before that there is nothing to pay out.',
            ]);
        }

        $unbatched = $run->payslips()
            ->with('employee')
            ->where('is_blocked', false)
            ->whereDoesntHave('batches')
            ->get();

        if ($unbatched->isEmpty()) {
            throw ValidationException::withMessages([
                'run' => 'Every payslip in this payroll is already in a batch.',
            ]);
        }

        return DB::transaction(function () use ($run, $user, $unbatched) {
            return $unbatched
                // An employee with no stated method is grouped as cash: SAL-08's
                // options are bank, MFS, cash and cheque, and cash is the only
                // one that needs nothing on file, so it is the sole grouping
                // that cannot be wrong about how they will be paid.
                ->groupBy(fn (Payslip $payslip) => (
                    $payslip->employee?->salary_payment_method ?? SalaryPaymentMethod::Cash
                )->value)
                ->map(function (Collection $payslips, string $method) use ($run, $user) {
                    $batch = DisbursementBatch::create([
                        'business_id' => $run->business_id,
                        'payroll_run_id' => $run->id,
                        'reference' => $this->nextReference($run, SalaryPaymentMethod::from($method)),
                        'method' => $method,
                        'created_by' => $user->id,
                    ]);

                    $batch->payslips()->attach($payslips->pluck('id')->all());

                    return $batch;
                })
                ->values();
        });
    }

    /**
     * A reference a human can read off a bank statement — the period, the run
     * version and the method, rather than an opaque id.
     */
    private function nextReference(PayrollRun $run, SalaryPaymentMethod $method): string
    {
        $period = $run->period;

        return sprintf(
            'DIS-%04d%02d-V%d-%s',
            $period?->year ?? now()->year,
            $period?->month ?? now()->month,
            $run->version,
            strtoupper(str_replace('_', '', $method->value)),
        );
    }
}
