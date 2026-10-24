<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CountAssets;
use App\Modules\Assets\Actions\DeleteAssetItems;
use App\Modules\Assets\Actions\DisposeAssetItem;
use App\Modules\Assets\Actions\RecordMaintenance;
use App\Modules\Assets\Actions\ReverseAssetImport;
use App\Modules\Assets\Actions\ReverseAssetMovement;
use App\Modules\Assets\Corrections\AssetCountActionPolicy;
use App\Modules\Assets\Corrections\AssetImportActionPolicy;
use App\Modules\Assets\Corrections\AssetItemActionPolicy;
use App\Modules\Assets\Corrections\AssetMaintenanceActionPolicy;
use App\Modules\Assets\Corrections\AssetMovementActionPolicy;
use App\Modules\Assets\Enums\AssetMovementStatus;
use App\Modules\Assets\Models\AssetCount;
use App\Modules\Assets\Models\AssetImportBatch;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\AssetMaintenanceRecord;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): putting the things register right
 * (FINAL-PLAN 3.5). One route per action; each asks the record's policy again
 * (hiding a button is not security, FINAL-PLAN 6.4), demands a reason of at
 * least 5 characters on the server (6.1), and runs the module's own action,
 * which re-checks under its lock (6.6, item 7).
 */
class AssetCorrectionController extends Controller
{
    use ValidatesActionReason;

    /** Delete one thing, or several from the list (a double import). */
    public function deleteItems(Request $request, DeleteAssetItems $delete): RedirectResponse
    {
        abort_unless($request->user()->can('assets.items.manage'), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.DeleteAssetItems::MAX],
            'ids.*' => ['integer'],
            ...$this->actionReasonRules('deletion'),
        ], ['ids.required' => 'Choose the things to delete.', ...$this->actionReasonMessages()]);

        $deleted = $delete->handle($data['ids'], $request->user(), $this->actionReason($request));

        return redirect()->route('assets.items.index')->with('success', $deleted === 1
            ? 'Moved to the Recycle bin. Its tag stays reserved.'
            : "{$deleted} things moved to the Recycle bin. Their tags stay reserved.");
    }

    public function dispose(Request $request, AssetItem $item, AssetItemActionPolicy $policy, DisposeAssetItem $dispose): RedirectResponse
    {
        $this->allowed($policy->availability($item, $request->user(), DocumentAction::Dispose));

        // Date first (Joy, 19 Oct 2026), then how, then why.
        $data = $request->validate([
            'disposed_on' => ['required', 'date'],
            'method' => ['required', Rule::in(AssetItem::DISPOSAL_METHODS)],
            ...$this->actionReasonRules('disposal'),
        ], [
            'disposed_on.required' => 'Say the day it left.',
            'method.required' => 'Say how it left.',
            ...$this->actionReasonMessages(),
        ]);

        $done = $dispose->dispose($item, $data, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$done->tag} is disposed. You can undo it for ".AssetItem::UNDO_DISPOSAL_DAYS.' days.');
    }

    public function undoDisposal(Request $request, AssetItem $item, AssetItemActionPolicy $policy, DisposeAssetItem $dispose): RedirectResponse
    {
        $this->allowed($policy->availability($item, $request->user(), DocumentAction::UndoDisposal));
        $request->validate($this->actionReasonRules('restore'), $this->actionReasonMessages());

        $back = $dispose->undo($item, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$back->tag} is back in the register, as it was before the disposal.");
    }

    /** Reverse a done hand-over, or Cancel a transfer still on its way. */
    public function reverseMovement(Request $request, AssetMovement $movement, AssetMovementActionPolicy $policy, ReverseAssetMovement $reverse): RedirectResponse
    {
        $cancel = $movement->status === AssetMovementStatus::Dispatched;
        $this->allowed($policy->availability($movement, $request->user(), $cancel ? DocumentAction::Cancel : DocumentAction::Reverse));
        $request->validate($this->actionReasonRules($cancel ? 'cancellation' : 'reversal'), $this->actionReasonMessages());

        if ($cancel) {
            $reverse->cancel($movement, $request->user(), $this->actionReason($request));

            return back()->with('success', "{$movement->number} cancelled. The things are back where they left.");
        }

        $reversal = $reverse->reverse($movement, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$movement->number} reversed by {$reversal->number}. Both stay in the history.");
    }

    public function cancelCount(Request $request, AssetCount $count, AssetCountActionPolicy $policy, CountAssets $counts): RedirectResponse
    {
        $this->allowed($policy->availability($count, $request->user(), DocumentAction::Cancel));
        $request->validate($this->actionReasonRules('cancellation'), $this->actionReasonMessages());

        $counts->cancel($count, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$count->number} cancelled. Nothing about the things changed, and the place can be counted again.");
    }

    public function reopenMaintenance(Request $request, AssetMaintenanceRecord $record, AssetMaintenanceActionPolicy $policy, RecordMaintenance $maintenance): RedirectResponse
    {
        $this->allowed($policy->availability($record, $request->user(), DocumentAction::Reverse));
        $request->validate($this->actionReasonRules('reversal'), $this->actionReasonMessages());

        $maintenance->reopen($record, $request->user(), $this->actionReason($request));

        return back()->with('success', "{$record->number} is open again. Complete it correctly or cancel it.");
    }

    public function reverseImport(Request $request, AssetImportBatch $batch, AssetImportActionPolicy $policy, ReverseAssetImport $reverse): RedirectResponse
    {
        $this->allowed($policy->availability($batch, $request->user(), DocumentAction::Reverse));
        $request->validate($this->actionReasonRules('reversal'), $this->actionReasonMessages());

        $result = $reverse->handle($batch, $request->user(), $this->actionReason($request));

        return back()->with('success', "Import {$batch->number} reversed: {$result['removed']} thing(s) moved to the Recycle bin"
            .($result['kept'] > 0 ? ", {$result['kept']} kept because they were used since." : '.'));
    }

    /**
     * 403 without the permission; otherwise a refused action says its one
     * sentence where the dialog shows errors.
     *
     * @throws ValidationException
     */
    private function allowed(ActionAvailability $availability): void
    {
        abort_unless(request()->user()->can($availability->permission), 403);

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
        }
    }
}
