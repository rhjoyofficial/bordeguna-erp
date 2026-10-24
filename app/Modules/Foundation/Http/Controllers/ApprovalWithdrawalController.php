<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\WithdrawApprovalRequest;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): POST approvals/{approvalRequest}/withdraw.
 *
 * The {approvalRequest} binding is business-scoped (BelongsToBusiness), so a
 * request of another business is a 404. The reason is checked here on the
 * server (5 characters at least); who may withdraw is the action's.
 */
class ApprovalWithdrawalController extends Controller
{
    use ValidatesActionReason;

    public function store(Request $request, ApprovalRequest $approvalRequest, WithdrawApprovalRequest $withdraw): RedirectResponse
    {
        $request->validate($this->actionReasonRules('withdrawal'), $this->actionReasonMessages());

        $label = $withdraw->handle($approvalRequest, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$label} withdrawn. Nothing was approved, and it is back with you.");
    }
}
