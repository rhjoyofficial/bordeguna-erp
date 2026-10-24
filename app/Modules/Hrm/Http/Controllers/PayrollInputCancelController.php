<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Hrm\Actions\CancelPayrollInput;
use App\Modules\Hrm\Corrections\PayrollInputCancelPolicy;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\SalaryRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): Cancel an approved overtime,
 * bonus award or salary revision while its payroll month still takes inputs.
 * The reason first (5 characters at least, on the server), then the policy's
 * answer, asked again under the lock by CancelPayrollInput.
 */
class PayrollInputCancelController extends Controller
{
    use ValidatesActionReason;

    public function __construct(
        private readonly PayrollInputCancelPolicy $policy,
        private readonly CancelPayrollInput $cancel,
    ) {}

    public function overtime(Request $request, OvertimeAssignment $overtime): RedirectResponse
    {
        return $this->run($request, $overtime);
    }

    public function bonusAward(Request $request, BonusAward $award): RedirectResponse
    {
        return $this->run($request, $award);
    }

    public function revision(Request $request, SalaryRevision $revision): RedirectResponse
    {
        return $this->run($request, $revision);
    }

    private function run(Request $request, Model $input): RedirectResponse
    {
        abort_unless($request->user()->can($this->policy->permissionFor($input)), 403);
        $request->validate($this->actionReasonRules('cancellation'), $this->actionReasonMessages());

        $this->cancel->handle($input, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$this->policy->label($input)} cancelled. Payroll leaves it out.");
    }
}
