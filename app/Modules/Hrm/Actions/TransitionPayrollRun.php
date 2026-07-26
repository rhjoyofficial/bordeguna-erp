<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a payroll run through the WFL-02 lifecycle.
 *
 * Kept out of the engine entirely: the engine turns inputs into figures, this
 * decides what may happen to those figures next. Every transition is guarded by
 * the enum's own state machine, so "can this be disbursed" has one answer
 * rather than one per controller.
 *
 * Submitting for approval hands over to the existing multi-stage workflow
 * engine rather than a second approval mechanism — which is also what puts a
 * payroll in the shared inbox alongside every other pending request (WFL-07).
 */
class TransitionPayrollRun
{
    public function __construct(
        private readonly NotifyStageApprovers $notify,
        private readonly PostPayrollRecoveries $recoveries,
    ) {}

    /**
     * @throws ValidationException
     */
    public function review(PayrollRun $run, User $user): PayrollRun
    {
        $this->guard($run, PayrollStatus::Reviewed);

        if ($run->hasBlockedLines()) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    '%d payslip%s blocked. Resolve them before review — PAY-09 and PAY-11 hold those lines deliberately.',
                    $run->blocked_count,
                    $run->blocked_count === 1 ? ' is' : 's are',
                ),
            ]);
        }

        $run->update([
            'status' => PayrollStatus::Reviewed,
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ]);

        // WFL-07: the approval itself runs on the shared pipeline.
        $approval = $run->submitForApproval($user);
        $this->notify->handle($approval, $this->summary($run));

        return $run->fresh();
    }

    /**
     * Called once the workflow's final stage approves.
     */
    public function markApproved(PayrollRun $run, User $user): PayrollRun
    {
        $run->update([
            'status' => PayrollStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        return $run->fresh();
    }

    public function markRejected(PayrollRun $run, ?string $reason = null): PayrollRun
    {
        $run->update([
            'status' => PayrollStatus::Rejected,
            'closed_reason' => $reason,
        ]);

        return $run->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function disburse(PayrollRun $run, User $user): PayrollRun
    {
        $this->guard($run, PayrollStatus::Disbursed);

        $run->update([
            'status' => PayrollStatus::Disbursed,
            'disbursed_at' => now(),
            'disbursed_by' => $user->id,
        ]);

        // PAY-11 / C8c: disbursement is the first moment a recovery deduction is
        // real money, so it is also the first moment the balance may shrink.
        $this->recoveries->handle($run);

        return $run->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function lock(PayrollRun $run, User $user): PayrollRun
    {
        $this->guard($run, PayrollStatus::Locked);

        $run->update([
            'status' => PayrollStatus::Locked,
            'locked_at' => now(),
            'locked_by' => $user->id,
        ]);

        return $run->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function cancel(PayrollRun $run, string $reason): PayrollRun
    {
        $this->guard($run, PayrollStatus::Cancelled);

        $run->update([
            'status' => PayrollStatus::Cancelled,
            'closed_reason' => $reason,
        ]);

        return $run->fresh();
    }

    /**
     * WFL-06: reopening before disbursement produces a **new version** rather
     * than editing figures somebody has already reviewed. The superseded run is
     * cancelled with its reason, so the chain reads as a history.
     *
     * After disbursement this is refused — WFL-06 sends those differences to the
     * next payroll as an arrear instead, which Phase 3d builds.
     *
     * @throws ValidationException
     */
    public function reopen(PayrollRun $run, string $reason): PayrollRun
    {
        if (! $run->status->isReopenable()) {
            throw ValidationException::withMessages([
                'status' => $run->status->isFinal()
                    ? 'A disbursed or locked payroll cannot be reopened — the difference goes to the next payroll as an arrear (WFL-06).'
                    : 'Only a reviewed or approved payroll needs reopening; this one can still be re-run directly.',
            ]);
        }

        return DB::transaction(function () use ($run, $reason) {
            $next = $run->period->runs()->create([
                'version' => $run->version + 1,
                'status' => PayrollStatus::Draft,
                'config' => $run->config,
                'reopened_from_id' => $run->id,
            ]);

            $run->update([
                'status' => PayrollStatus::Cancelled,
                'closed_reason' => sprintf('Reopened as version %d: %s', $next->version, $reason),
            ]);

            return $next;
        });
    }

    /**
     * @throws ValidationException
     */
    private function guard(PayrollRun $run, PayrollStatus $target): void
    {
        if (! $run->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'A %s payroll cannot move to %s.',
                    $run->status->label(),
                    $target->label(),
                ),
            ]);
        }
    }

    private function summary(PayrollRun $run): string
    {
        return sprintf(
            '%s payroll v%d — %d employee(s), net %s',
            $run->period?->label(),
            $run->version,
            $run->employee_count,
            number_format((float) $run->total_net, 2),
        );
    }
}
