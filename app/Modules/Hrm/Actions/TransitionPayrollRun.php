<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollRun;
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
        private readonly PostLoanRepayments $loanRepayments,
        private readonly PostPayrollAccrual $accrual,
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
        return DB::transaction(function () use ($run, $user) {
            $run = $this->lockAwaitingApproval($run);

            $run->update([
                'status' => PayrollStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);

            // ⚠⚠ THE ACCRUAL. Approval is the moment the obligation exists, so
            // it is the moment the cost belongs in the books (C-GL-5). Posted
            // automatically from the business event and never by hand, per
            // Joy's principle of 2026-08-03 - a wage figure with two possible
            // origins can never be conclusively reconciled.
            //
            // ⚠ In the same transaction as the status change: a run that is
            // approved but unposted, or posted but unapproved, is a state
            // nobody could reconcile and neither is recoverable by retrying.
            $this->accrual->handle($run, $user);

            return $run->fresh();
        });
    }

    public function markRejected(PayrollRun $run, User $user, ?string $reason = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $user, $reason) {
            $run = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            // ⚠ A stale request left on a run that was already cancelled or
            // superseded (from before cancel/reopen withdrew it) may still be
            // rejected, to clear it from the inbox - but it must not rewrite
            // that run's own outcome. Only a run actually awaiting approval
            // becomes Rejected.
            if ($run->status !== PayrollStatus::Reviewed) {
                return $run;
            }

            $run->update([
                'status' => PayrollStatus::Rejected,
                'closed_reason' => $reason,
            ]);

            // Defensive: a rejected run has normally never been approved and so
            // has no journal. `reverse` returns null when there is nothing to
            // undo, and the alternative is a path where a posted cost survives
            // the run that explained it.
            $this->accrual->reverse($run, $user);

            return $run->fresh();
        });
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

        // Disbursement is the first moment any of these deductions is real
        // money, so it is the only moment a balance may shrink. Both ledgers are
        // posted here for the same reason: a run recalculated before this point
        // must always see the balances it started with.
        //
        // ADJ-07 loans first, then PAY-11's carry-forward. The order is
        // immaterial — each reads its own payslip lines and neither derives
        // anything from the other — but keeping it fixed makes a ledger read in
        // a predictable sequence.
        $this->loanRepayments->handle($run);
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
    public function cancel(PayrollRun $run, User $user, string $reason): PayrollRun
    {
        $this->guard($run, PayrollStatus::Cancelled);

        return DB::transaction(function () use ($run, $user, $reason) {
            $run->update([
                'status' => PayrollStatus::Cancelled,
                'closed_reason' => $reason,
            ]);

            // ⚠⚠ A cancelled run must leave the approval inbox. A Reviewed run
            // has a live request, and approving it later posted the accrual of
            // a payroll nobody meant to pay.
            $this->withdrawApproval($run, $user, 'payroll cancelled: '.$reason);

            // ⚠ DEFENSIVE, and worth saying why rather than implying more.
            // `transitionableTo()` does not allow Approved to reach Cancelled
            // today - an approved run goes to Disbursed or is reopened - so no
            // run arriving here has a journal, and this reverses nothing.
            //
            // It stays because the transition map is the kind of thing that
            // gets one more arrow added to it, and the day Approved becomes
            // cancellable the cost has to come back out of the books on the
            // same commit. `reverse` returns null when there is nothing to undo.
            $this->accrual->reverse($run, $user);

            return $run->fresh();
        });
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
    public function reopen(PayrollRun $run, User $user, string $reason): PayrollRun
    {
        if (! $run->status->isReopenable()) {
            throw ValidationException::withMessages([
                'status' => $run->status->isFinal()
                    ? 'A disbursed or locked payroll cannot be reopened — the difference goes to the next payroll as an arrear (WFL-06).'
                    : 'Only a reviewed or approved payroll needs reopening; this one can still be re-run directly.',
            ]);
        }

        return DB::transaction(function () use ($run, $user, $reason) {
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

            // ⚠⚠ The superseded version's request goes too, or approving it
            // later posts a second accrual next to the new version's.
            $this->withdrawApproval($run, $user, sprintf('reopened as version %d: %s', $next->version, $reason));

            // ⚠⚠ The superseded version's cost comes back out. Without this the
            // new version's accrual would ADD to the old one and the wage bill
            // would double every time somebody reopened a payroll - a mistake
            // that would look like a rounding problem for exactly one month and
            // then like fraud.
            $this->accrual->reverse($run, $user);

            return $next;
        });
    }

    /**
     * Re-read the run under a row lock and refuse unless it is still the one
     * awaiting approval: Reviewed, and the newest version of its period.
     *
     * ⚠⚠ The status on the request is not enough. A cancelled or reopened run
     * kept its pending request, and approving it posted a second accrual for
     * the same month. The controller calls this BEFORE recording a stage
     * decision too, so a stale run cannot be approved at any stage.
     *
     * @throws ValidationException
     */
    public function lockAwaitingApproval(PayrollRun $run): PayrollRun
    {
        $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== PayrollStatus::Reviewed) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'This payroll is %s, so it is no longer waiting for approval. Only a reviewed payroll can be approved.',
                    $locked->status->label(),
                ),
            ]);
        }

        $newest = (int) PayrollRun::query()
            ->where('payroll_period_id', $locked->payroll_period_id)
            ->max('version');

        if ($newest > $locked->version) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Version %d of this payroll exists, so version %d can no longer be approved.',
                    $newest,
                    $locked->version,
                ),
            ]);
        }

        return $locked;
    }

    /**
     * Take a run's pending approval out of every inbox.
     *
     * ⚠ The approval engine has no separate "withdrawn" state, so this closes
     * the request as rejected with a note that says it was withdrawn and why.
     * The decision row names who withdrew it.
     */
    private function withdrawApproval(PayrollRun $run, User $user, string $why): void
    {
        $approval = $run->approvalRequest()->first();

        if ($approval === null || ! $approval->isPending()) {
            return;
        }

        $approval->reject($user, 'Withdrawn: '.$why);
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
