<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\EditPendingPayrollInput;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): Edit a payroll adjustment
 * or a staff loan while it waits for its first approval (EditPendingPayrollInput).
 * The payroll manager's own permission, as raising them.
 */
class PendingPayrollInputController extends Controller
{
    public function __construct(private readonly EditPendingPayrollInput $edit) {}

    public function adjustment(Request $request, PayrollAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'type' => ['required', new EnumRule(PayrollAdjustmentType::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payroll_period_id' => ['required', Rule::exists('payroll_periods', 'id')->where('business_id', app(BusinessContext::class)->id())],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->edit->adjustment($adjustment, $data, $request->user());

        return back()->with('success', "Adjustment #{$adjustment->id} changed. It is still waiting for approval.");
    }

    public function loan(Request $request, Loan $loan): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $data = $request->validate([
            'principal' => ['required', 'numeric', 'min:0.01'],
            'installment_amount' => ['required', 'numeric', 'min:0.01'],
            'start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->edit->loan($loan, $data, $request->user());

        return back()->with('success', "{$loan->reference} changed. It is still waiting for approval.");
    }
}
