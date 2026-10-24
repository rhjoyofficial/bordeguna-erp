<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Models\User;
use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Optional, beside DocumentCorrector (corrections wave 1 gaps, 24 Oct 2026):
 * the type's own date rule, where "the original's date" is not what the
 * correction is about (CorrectionDatePolicy: "a module overrides it only for
 * its own named exception").
 *
 * The one user today: a price credit or an extra charge on a sales invoice.
 * Nothing on the invoice is reversed; a NEW document is issued on the date the
 * person types (FINAL-PLAN 4.2: "Credit note in the current period"). So the
 * month that matters is the typed date's month, not the invoice's: an October
 * credit on a September invoice is an ordinary open-month action, and a
 * credit dated inside a closed month is the Owner's one-document exception.
 *
 * ⚠ Additive: a corrector that does not implement this uses the bound
 * CorrectionDatePolicy (OriginalDatePolicy) exactly as before.
 */
interface ResolvesCorrectionDates
{
    public function correctionDates(Model $document, DocumentAction $action, ReverseDateChoice $choice, ?Carbon $successorDate, ?User $authority): CorrectionDates;
}
