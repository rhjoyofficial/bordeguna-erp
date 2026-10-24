<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Hrm\Corrections\ShowCauseNoticeActionPolicy;
use App\Modules\Hrm\Models\ShowCauseNotice;
use App\Modules\Hrm\Notifications\ShowCauseIssuedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Withdraw a show cause notice (corrections wave 3, 24 Oct 2026; FINAL-PLAN
 * 3.4: "Withdraw with reason and date. The employee sees the withdrawal only
 * if the letter was delivered").
 *
 * The notice keeps its wording and its row; it is marked Withdrawn on the date
 * HR gives (not before it was issued, not in the future), with who and why.
 * It stops counting in the lateness register. If the letter reached the
 * employee, their bell rings once more and their copy shows it withdrawn; if
 * it never reached them, it simply leaves their list.
 */
class WithdrawShowCauseNotice
{
    public function __construct(private readonly ShowCauseNoticeActionPolicy $policy) {}

    public function handle(ShowCauseNotice $notice, Carbon $withdrawnOn, User $by, ActionReason $reason): ShowCauseNotice
    {
        $withdrawn = DB::transaction(function () use ($notice, $withdrawnOn, $by, $reason) {
            $current = ShowCauseNotice::query()->whereKey($notice->id)->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($current->load('employee'), $by, DocumentAction::Withdraw);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            if ($withdrawnOn->lt($current->issued_at->copy()->startOfDay())) {
                throw new InvalidArgumentException('A notice cannot be withdrawn before the day it was issued ('.$current->issued_at->format('d/m/Y').').');
            }

            $current->update([
                'withdrawn_on' => $withdrawnOn->toDateString(),
                'withdrawn_at' => now(),
                'withdrawn_by' => $by->id,
                'withdraw_reason' => $reason->display(),
            ]);

            CorrectionLog::event('withdrawn', $by, $current, [
                'number' => $this->policy->numberOf($current),
                'withdrawn_on' => $withdrawnOn->toDateString(),
                'reason' => $reason->display(),
                'employee_told' => $current->wasDelivered(),
            ]);

            return $current;
        });

        $employee = $withdrawn->employee;

        if ($withdrawn->wasDelivered() && $employee?->user !== null) {
            $employee->user->notify(new ShowCauseIssuedNotification(
                'The show cause notice "'.$withdrawn->subject.'" has been withdrawn.',
                route('hrm.my-notices.show', $withdrawn->id),
            ));
        }

        return $withdrawn;
    }
}
