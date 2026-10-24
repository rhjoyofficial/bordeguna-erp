<?php

namespace App\Modules\Foundation\Corrections\Recycle;

use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ B24 (24 Oct 2026, Q5 = A): an older list's own Delete route, now the same
 * as the masters' one. ⚠ Once a model uses SoftDeletes, `delete()` no longer
 * meets the RESTRICT foreign keys that used to refuse a used row, so the used
 * check (whyNotRemovable) is what refuses now, as a sentence; then a reason;
 * then the row goes to the Recycle bin, never out of the database.
 */
trait BinsFromList
{
    use ValidatesActionReason;

    protected function binFromList(Request $request, Model&Removable $record): RedirectResponse
    {
        if (($why = $record->whyNotRemovable()) !== null) {
            return back()->with('error', $why);
        }

        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());
        $reason = $this->actionReason($request);

        DB::transaction(fn () => $record::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail()
            ->recycle($request->user(), $reason));

        return back()->with('success', "{$record->binLabel()} moved to the Recycle bin. The Owner can restore it.");
    }
}
