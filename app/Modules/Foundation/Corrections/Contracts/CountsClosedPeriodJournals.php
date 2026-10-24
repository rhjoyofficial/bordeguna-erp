<?php

namespace App\Modules\Foundation\Corrections\Contracts;

use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\ReplacementData;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Optional, beside DocumentCorrector (corrections wave 1 gaps, 24 Oct 2026):
 * how many journals THIS correction posts on the closed dates of the Owner's
 * one-document pass, when the default count is wrong for the type.
 *
 * The default (CorrectionService::plan) is "one journal per leg": one for the
 * reversal, one more when a Correct's successor lands on a closed date. Two
 * types break that, and were refused in a closed month even for the Owner:
 *
 *   - a production run posts NO journal when its waste is absorbed, and its
 *     successor only knows whether it posts a rounding residue once FIFO has
 *     priced its inputs: a range, not a number;
 *   - a price credit or an extra charge reverses nothing: only the new
 *     document posts.
 *
 * The pass is still granted for at most `max` journals and refuses a
 * journal beyond it (ClosedPeriodExceptions::consume); closing it checks the
 * journals really posted fell inside [min, max]. With max 0 the pass covers
 * only the stock movements on those dates and posts no journal at all.
 *
 * ⚠ Additive: a corrector that does not implement this keeps the old count.
 */
interface CountsClosedPeriodJournals
{
    /** @return array{0: int, 1: int} [at least, at most] */
    public function closedPeriodJournals(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array;
}
