<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DIS-01's "whole batch paid": confirm the batch and every payslip in it is
 * recorded as paid in one act.
 *
 * It does not bypass the per-payslip path — it walks it. Each payslip still gets
 * its own payment event through `RecordPayslipPayment`, so the Correction C9c
 * reconciliation runs for each, and confirming a batch of two hundred leaves
 * exactly the record that confirming them one at a time would have left. That is
 * the point: "whole batch" and "employee-wise" are the same mechanism at two
 * speeds, not two mechanisms that can disagree.
 *
 * Already-paid payslips are skipped rather than paid twice, so a confirmation
 * after a handful of individual payments settles only the remainder.
 */
class ConfirmDisbursementBatch
{
    public function __construct(private readonly RecordPayslipPayment $payments) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        DisbursementBatch $batch,
        User $user,
        ?Carbon $paidOn = null,
        ?string $adviceReference = null,
        ?MoneyAccount $moneyAccount = null,
    ): int {
        if ($batch->isConfirmed()) {
            throw ValidationException::withMessages([
                'batch' => 'This batch has already been confirmed.',
            ]);
        }

        // ⚠⚠ Confirming means the money has GONE. A confirmation that cannot say
        // which account it went from is exactly the record that was missing
        // every time somebody was paid, and no cash book can be produced for the
        // month afterwards without it.
        if ($moneyAccount === null) {
            throw ValidationException::withMessages([
                'money_account_id' => 'Say which bank, cash or MFS account this payment came from.',
            ]);
        }

        // ⚠ Paying one business's salaries from another's account is obvious in
        // a sentence and invisible in a dropdown.
        if ($moneyAccount->business_id !== $batch->business_id) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account belongs to a different business.',
            ]);
        }

        if (! $moneyAccount->is_active) {
            throw ValidationException::withMessages([
                'money_account_id' => 'That account is no longer active.',
            ]);
        }

        return DB::transaction(function () use ($batch, $user, $paidOn, $adviceReference, $moneyAccount) {
            $paid = 0;

            foreach ($batch->payslips()->with(['run', 'employee', 'payments'])->get() as $payslip) {
                $outstanding = round((float) $payslip->net - $payslip->paidTotal(), 2);

                if ($outstanding <= 0.005) {
                    continue;
                }

                $this->payments->paid(
                    $payslip,
                    $user,
                    $outstanding,
                    $batch->method,
                    $paidOn,
                    $adviceReference ?? $batch->advice_reference,
                    $batch,
                );

                $paid++;
            }

            $batch->update([
                'advice_reference' => $adviceReference ?? $batch->advice_reference,
                'money_account_id' => $moneyAccount->id,
                'confirmed_at' => now(),
                'confirmed_by' => $user->id,
            ]);

            return $paid;
        });
    }

    /**
     * Records a failure against every payslip in the batch that is still
     * outstanding — a whole advice file the bank rejected.
     *
     * @throws ValidationException
     */
    public function fail(DisbursementBatch $batch, User $user, string $reason, ?Carbon $failedOn = null): int
    {
        return DB::transaction(function () use ($batch, $user, $reason, $failedOn) {
            $failed = 0;

            foreach ($batch->payslips()->with(['run', 'employee', 'payments'])->get() as $payslip) {
                if (round((float) $payslip->net - $payslip->paidTotal(), 2) <= 0.005) {
                    continue;
                }

                $this->payments->failed($payslip, $user, $reason, $batch->method, $failedOn, $batch);
                $failed++;
            }

            return $failed;
        });
    }

    /**
     * @return Collection<int, Payslip>
     */
    public function outstandingIn(DisbursementBatch $batch)
    {
        return $batch->payslips()->with('payments')->get()
            ->filter(fn (Payslip $p) => round((float) $p->net - $p->paidTotal(), 2) > 0.005)
            ->values();
    }
}
