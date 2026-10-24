<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Support\MasterRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ B24 (24 Oct 2026, R113; Joy's Q5 = A): Delete, Switch off and Reactivate
 * for every master list row (MasterRecords says which lists).
 *
 *   Delete      never used only; to the Recycle bin with a reason; the Owner restores
 *   Switch off  with a reason; history keeps pointing at it
 *   Reactivate  a switched-off row comes back
 *
 * ⚠ The permission is the one that already edits that list, checked here per
 * type. The row is read through its model, so the business scope applies and
 * another business's row is a 404. Locked in the transaction: a double click
 * finds the row already gone (404) or already off (a sentence).
 */
class MasterRecordController extends Controller
{
    use ValidatesActionReason;

    public function delete(Request $request, string $type, int $id): RedirectResponse
    {
        $spec = $this->authorizeType($type);
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());
        $reason = $this->actionReason($request);

        $label = DB::transaction(function () use ($spec, $id, $request, $reason) {
            $record = $this->locked($spec['model'], $id);
            $record->recycle($request->user(), $reason);

            return $record->binLabel();
        });

        return back()->with('success', "{$label} moved to the Recycle bin. The Owner can restore it.");
    }

    public function switchOff(Request $request, string $type, int $id): RedirectResponse
    {
        $spec = $this->authorizeType($type, switch: true);
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());
        $reason = $this->actionReason($request);

        $label = DB::transaction(function () use ($spec, $id, $request, $reason) {
            $record = $this->locked($spec['model'], $id);

            $why = ! $record->is_active ? "{$record->binLabel()} is already switched off." : $record->whyNotDeactivatable();

            if ($why !== null) {
                throw ValidationException::withMessages(['reason_text' => $why]);
            }

            $record->update(['is_active' => false]);
            CorrectionLog::event('deactivated', $request->user(), $record, ['reason' => $reason->display()]);

            return $record->binLabel();
        });

        return back()->with('success', "{$label} switched off. Its history stays.");
    }

    public function reactivate(Request $request, string $type, int $id): RedirectResponse
    {
        $spec = $this->authorizeType($type, switch: true);

        $label = DB::transaction(function () use ($spec, $id, $request) {
            $record = $this->locked($spec['model'], $id);

            if ($record->is_active) {
                throw ValidationException::withMessages(['record' => "{$record->binLabel()} is already active."]);
            }

            $record->update(['is_active' => true]);
            CorrectionLog::event('reactivated', $request->user(), $record, []);

            return $record->binLabel();
        });

        return back()->with('success', "{$label} is active again.");
    }

    private function authorizeType(string $type, bool $switch = false): array
    {
        $spec = MasterRecords::type($type);
        $this->authorize($spec['permission']);
        abort_if($switch && ! $spec['switch'], 404);

        return $spec;
    }

    /** @param class-string<Model> $class */
    private function locked(string $class, int $id): Model
    {
        return $class::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
