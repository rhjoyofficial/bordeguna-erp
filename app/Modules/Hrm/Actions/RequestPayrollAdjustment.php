<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a manual payroll adjustment (ADJ-08, ADJ-09).
 *
 * ADJ-09 ticked "সবগুলো" — all four controls — so all four are enforced, not
 * three and a strong suggestion:
 *
 *  1. **Reason** — a required column.
 *  2. **Attachment** — required here, at submission. The Attachment engine
 *     stores polymorphically, so there is nothing to attach a file to until the
 *     row exists; the two therefore have to happen in one transaction, and the
 *     upload is refused before the row is written rather than after.
 *  3. **Approval** — the existing workflow engine, like every other approvable.
 *  4. **Effective payroll month** — a foreign key to a real period, so an
 *     adjustment can never be "sometime soon", and never lands on a month whose
 *     payroll has already closed.
 *
 * The one carve-out is an arrear, which SAL-05 *generates* from an approved
 * salary revision. It has no file to attach because its evidence is the revision
 * — stronger provenance than an upload, and produced by the system rather than
 * accepted on trust. Generated arrears therefore go through
 * `ApplySalaryRevision`, not through here.
 */
class RequestPayrollAdjustment
{
    public function __construct(
        private readonly StoreAttachment $storeAttachment,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        PayrollAdjustmentType $type,
        float $amount,
        PayrollPeriod $period,
        string $reason,
        ?UploadedFile $evidence,
        User $requestedBy,
    ): PayrollAdjustment {
        if ($amount <= 0) {
            // The type decides the direction; the amount is always positive, so
            // a stray minus sign can never flip a penalty into a payment.
            throw ValidationException::withMessages(['amount' => 'An adjustment must be more than zero. Its type decides whether it adds or deducts.']);
        }

        if ($type->isSystemGenerated()) {
            throw ValidationException::withMessages([
                'type' => 'An arrear is produced by an approved salary revision (SAL-05), not entered by hand.',
            ]);
        }

        // ADJ-09's attachment requirement.
        if ($evidence === null) {
            throw ValidationException::withMessages(['evidence' => 'ADJ-09 requires supporting evidence for every manual adjustment.']);
        }

        $this->guardPeriodOpen($period);

        return DB::transaction(function () use ($employee, $type, $amount, $period, $reason, $evidence, $requestedBy) {
            $adjustment = PayrollAdjustment::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'amount' => round($amount, 2),
                'payroll_period_id' => $period->id,
                'reason' => $reason,
                'requested_by' => $requestedBy->id,
            ]);

            $this->storeAttachment->handle($adjustment, $evidence, 'evidence', $requestedBy);

            $approval = $adjustment->submitForApproval($requestedBy);
            $this->notify->handle($approval, $adjustment->summaryLine());

            return $adjustment->fresh();
        });
    }

    /**
     * ADJ-09's effective payroll month has to be a month that can still be paid.
     * WFL-06 is explicit that once a payroll is disbursed, differences travel to
     * the *next* period — so pointing an adjustment at a closed one would be
     * accepting an instruction the system cannot honour.
     *
     * ⚠⚠ "Closed" starts at Reviewed, not at Disbursed: nothing recalculates a
     * reviewed or approved run, so an adjustment aimed at one was approved and
     * never paid. PayrollInputWindow is the one definition.
     *
     * @throws ValidationException
     */
    private function guardPeriodOpen(PayrollPeriod $period): void
    {
        PayrollInputWindow::assertAccepts($period);
    }
}
