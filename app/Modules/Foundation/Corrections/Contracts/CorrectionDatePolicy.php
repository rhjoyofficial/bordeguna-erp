<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Models\User;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use Illuminate\Support\Carbon;

/**
 * Which dates a correction lands on (PHASE-0-CONTRACT v2, 1.9). Pure: reads the
 * period boundary, writes nothing. No global date picker: a module overrides it
 * only for its own named exception (sold stock up to Tk 10,000, payroll rule #18).
 *
 * `$authority` is the person whose authority a closed month would use: the
 * person asking, or for a request the approving Owner (or the Owner who asked).
 */
interface CorrectionDatePolicy
{
    public function resolve(Carbon $originalDate, DocumentAction $action, ReverseDateChoice $choice, ?Carbon $successorDate, ?User $authority): CorrectionDates;
}
