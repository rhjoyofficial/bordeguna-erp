<?php

namespace App\Modules\Foundation\Corrections\Recycle;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\Contracts\Removable;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\Owners;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Laravel SoftDeletes + who + why (PHASE-0-CONTRACT v2, 1.5). Opt-in per model,
 * listed in config('recycle_bin.models').
 *
 * ⛔⛔ NO Prunable, NO MassPrunable, NO forceDelete route, NO purge job (owner,
 * 17 Oct 2026: "no permanent purge, ever"). A structural test enforces it.
 * ⚠ Only the Owner restores (answer 1), checked here as well as at the route.
 */
trait Recyclable
{
    use SoftDeletes;

    public function initializeRecyclable(): void
    {
        $this->mergeCasts(['deleted_at' => 'datetime']);
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * @throws ValidationException
     */
    public function recycle(User $by, ActionReason $reason): void
    {
        if ($this instanceof Removable && ($why = $this->whyNotRemovable()) !== null) {
            throw ValidationException::withMessages(['reason_text' => $why]);
        }

        $this->forceFill(['deleted_by' => $by->id, 'delete_reason' => $reason->display()])->saveQuietly();
        $this->delete();

        CorrectionLog::event('deleted', $by, $this, ['reason' => $reason->display()]);
    }

    /**
     * @throws ValidationException
     */
    public function restoreFromBin(User $by, ActionReason $reason): void
    {
        if (! Owners::is($by)) {
            throw ValidationException::withMessages(['reason_text' => 'Only the Owner can restore from the Recycle bin.']);
        }

        if (method_exists($this, 'whyNotRestorable') && ($why = $this->whyNotRestorable()) !== null) {
            throw ValidationException::withMessages(['reason_text' => $why]);
        }

        $this->restore();
        $this->forceFill(['deleted_by' => null, 'delete_reason' => null])->saveQuietly();

        CorrectionLog::event('restored', $by, $this, ['reason' => $reason->display()]);
    }
}
