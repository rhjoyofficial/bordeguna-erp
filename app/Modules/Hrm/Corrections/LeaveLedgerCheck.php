<?php

namespace App\Modules\Hrm\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Hrm\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): a cancelled leave gives its days back
 * into the year it took them from. Once that year's year-end is committed the
 * carry-forward and lapse were worked out from the balance as it stood, so a
 * give-back afterwards would leave them wrong. Refused, in one sentence.
 */
class LeaveLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::LeaveLedger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $document instanceof LeaveRequest || ($year = LeaveRequestActionPolicy::closedYear($document)) === null) {
            return null;
        }

        return new Blocker('leave_year_closed', "The {$year} leave year-end is done, so its days can no longer be given back.");
    }
}
