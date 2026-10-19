<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\CancelStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransferShort;
use App\Modules\Inventory\Corrections\StockTransferActionPolicy;
use App\Modules\Inventory\Models\StockTransfer;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): Cancel and Receive part on a
 * transfer still on the road (FINAL-PLAN 3.3). The policy is asked again on
 * every write; a reason is required for both, on the server.
 */
class StockTransferCorrectionController extends Controller
{
    use ValidatesActionReason;

    public function __construct(private readonly StockTransferActionPolicy $policy) {}

    public function cancel(Request $request, StockTransfer $stockTransfer, CancelStockTransfer $action): RedirectResponse
    {
        $availability = $this->policy->availability($stockTransfer, $request->user(), DocumentAction::Cancel);

        abort_unless($request->user()->can($availability->permission), 403);

        $request->validate($this->actionReasonRules('cancellation'), $this->actionReasonMessages());

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
        }

        try {
            $action->handle($stockTransfer, $request->user(), $this->actionReason($request));
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
        }

        return back()->with('success', "Transfer #{$stockTransfer->id} cancelled. The stock is back where it left.");
    }

    public function receivePart(Request $request, StockTransfer $stockTransfer, ReceiveStockTransferShort $action): RedirectResponse
    {
        abort_unless($request->user()->can(StockTransferActionPolicy::PERMISSION), 403);

        $data = $request->validate([
            'received_quantity' => ['required', 'numeric', 'min:0'],
            'loss_reason_id' => ['required', Rule::exists('reasons', 'id')->where('business_id', app(BusinessContext::class)->id())->where('context', 'inventory.stock_adjustment')],
            ...$this->actionReasonRules('correction'),
        ], [
            'received_quantity.required' => 'Enter how many arrived.',
            'loss_reason_id.required' => 'Choose what happened to the missing stock.',
            ...$this->actionReasonMessages(),
        ]);

        if (($blocker = $this->policy->mayReceivePart($stockTransfer, $request->user())) !== null) {
            throw ValidationException::withMessages(['received_quantity' => $blocker->message]);
        }

        try {
            $shortages = $action->handle($stockTransfer, (string) $data['received_quantity'], Reason::findOrFail($data['loss_reason_id']), $request->user(), $this->actionReason($request));
        } catch (RuntimeException|InvalidArgumentException|DomainException $e) {
            throw ValidationException::withMessages(['received_quantity' => $e->getMessage()]);
        }

        $waiting = $shortages->contains(fn ($adjustment) => $adjustment->approvalRequest?->isPending());

        return back()->with('success', "Transfer #{$stockTransfer->id} received short. The missing stock is recorded as a stock correction"
            .($waiting ? ' waiting for approval.' : '.'));
    }
}
