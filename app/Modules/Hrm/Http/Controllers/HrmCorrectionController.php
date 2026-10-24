<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Hrm\Actions\CancelExpenseClaimApproval;
use App\Modules\Hrm\Actions\CancelPayrollAdjustment;
use App\Modules\Hrm\Actions\CancelStaffLoan;
use App\Modules\Hrm\Actions\ReverseExpenseClaimPayment;
use App\Modules\Hrm\Corrections\ExpenseClaimActionPolicy;
use App\Modules\Hrm\Corrections\PayrollAdjustmentActionPolicy;
use App\Modules\Hrm\Corrections\StaffLoanActionPolicy;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): the HRM money records' Cancel and
 * Reverse buttons (FINAL-PLAN 3.4). Each asks its policy first (a refused
 * action answers with its blocker sentence), demands a reason of at least five
 * characters on the server, and runs the module's own action, which asks the
 * policy once more under its row lock.
 *
 * ⚠ The routes sit outside any permission middleware on purpose: who may act
 * is the policy's answer (the claim's approver, the Owner, the payroll
 * manager), and a middleware would refuse an Owner the policy allows.
 */
class HrmCorrectionController extends Controller
{
    use ValidatesActionReason;

    public function cancelClaimApproval(Request $request, ExpenseClaim $claim, ExpenseClaimActionPolicy $policy, CancelExpenseClaimApproval $action): RedirectResponse
    {
        abort_unless($policy->canView($claim, $request->user()) || $policy->isApproverOf($claim, $request->user()), 403);
        $this->check($request, $policy->availability($claim, $request->user(), DocumentAction::Cancel), 'cancellation');

        $action->handle($claim, $request->user(), $this->actionReason($request));

        return back()->with('success', "Approval of {$claim->claim_number} cancelled. The claim is back with {$claim->employee?->fullName()} to fix and send again.");
    }

    public function reverseClaimPayment(Request $request, ExpenseClaim $claim, ExpenseClaimActionPolicy $policy, ReverseExpenseClaimPayment $action): RedirectResponse
    {
        abort_unless($policy->canView($claim, $request->user()), 403);
        $this->check($request, $policy->availability($claim, $request->user(), DocumentAction::Reverse), 'reversal');

        $action->handle($claim, $request->user(), $this->actionReason($request));

        return back()->with('success', "Payment of {$claim->claim_number} reversed. The claim is approved and waiting to be paid again.");
    }

    public function cancelAdjustment(Request $request, PayrollAdjustment $adjustment, PayrollAdjustmentActionPolicy $policy, CancelPayrollAdjustment $action): RedirectResponse
    {
        abort_unless($policy->canView($adjustment, $request->user()), 403);
        $this->check($request, $policy->availability($adjustment, $request->user(), DocumentAction::Cancel), 'cancellation');

        $cancelled = $action->handle($adjustment, $request->user(), $this->actionReason($request));

        return back()->with('success', $cancelled->cancel_offset_id === null
            ? "Adjustment #{$adjustment->id} cancelled. It is dropped from the {$cancelled->period?->label()} payroll."
            : "Adjustment #{$adjustment->id} cancelled. An opposite line goes on the {$cancelled->cancelOffset?->period?->label()} payroll.");
    }

    public function cancelLoan(Request $request, Loan $loan, StaffLoanActionPolicy $policy, CancelStaffLoan $action): RedirectResponse
    {
        abort_unless($policy->canView($loan, $request->user()), 403);
        $this->check($request, $policy->availability($loan, $request->user(), DocumentAction::Cancel), 'cancellation');

        $action->handle($loan, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$loan->reference} cancelled. The payout is reversed and nothing will be taken from salary.");
    }

    /**
     * The reason first (a 422 on the reason field), then the policy's answer.
     *
     * @throws ValidationException
     */
    private function check(Request $request, ActionAvailability $availability, string $context): void
    {
        $request->validate($this->actionReasonRules($context), $this->actionReasonMessages());

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
        }
    }
}
