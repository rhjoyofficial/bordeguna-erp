<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayrollUnlockRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reopening a locked payroll (WFL-08).
 *
 * The normal path is a three-stage approval — HR Head, then Finance, then
 * Management — carried by the existing workflow engine. Those stages are
 * defined by **permission** rather than role, because HR Head and Management do
 * not exist as roles in this system and inventing them would be an
 * architectural assumption; Joy chose permissions so the chain works today and
 * is bound to whatever real roles emerge, through the existing Roles screen.
 *
 * WFL-08 also allows a Super Admin emergency override. It is honoured, but
 * never silently: the request records that the chain was bypassed, states why,
 * and is activity-logged like everything else.
 */
class RequestPayrollUnlock
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    /**
     * @throws ValidationException
     */
    public function handle(PayrollRun $run, User $user, string $reason, bool $emergencyOverride = false, ?string $overrideReason = null): PayrollUnlockRequest
    {
        if ($run->status !== PayrollStatus::Locked) {
            throw ValidationException::withMessages([
                'payroll_run_id' => 'Only a locked payroll needs an unlock request.',
            ]);
        }

        if ($emergencyOverride) {
            $this->guardOverride($user, $overrideReason);
        }

        return DB::transaction(function () use ($run, $user, $reason, $emergencyOverride, $overrideReason) {
            $request = PayrollUnlockRequest::create([
                'payroll_run_id' => $run->id,
                'reason' => $reason,
                'is_emergency_override' => $emergencyOverride,
                'override_reason' => $overrideReason,
                'requested_by' => $user->id,
            ]);

            if ($emergencyOverride) {
                return $this->apply($request);
            }

            $approval = $request->submitForApproval($user);
            $this->notify->handle($approval, $request->summaryLine());

            return $request->fresh();
        });
    }

    /**
     * Puts an approved (or overridden) unlock into effect: the locked run is
     * superseded by a fresh Draft version, so the figures that were locked stay
     * exactly as they were.
     */
    public function apply(PayrollUnlockRequest $request): PayrollUnlockRequest
    {
        return DB::transaction(function () use ($request) {
            $run = $request->run;

            if ($run === null || $request->isApplied()) {
                return $request->fresh();
            }

            $run->period->runs()->create([
                'version' => $run->version + 1,
                'status' => PayrollStatus::Draft,
                'config' => $run->config,
                'reopened_from_id' => $run->id,
            ]);

            $request->update(['applied_at' => now()]);

            return $request->fresh();
        });
    }

    /**
     * @throws ValidationException
     */
    private function guardOverride(User $user, ?string $overrideReason): void
    {
        if (! $user->is_super_admin) {
            throw ValidationException::withMessages([
                'is_emergency_override' => 'Only a Super Admin may bypass the unlock approval chain (WFL-08).',
            ]);
        }

        if (blank($overrideReason)) {
            throw ValidationException::withMessages([
                'override_reason' => 'An emergency override requires a stated reason (WFL-08).',
            ]);
        }
    }
}
