<?php

namespace App\Modules\Foundation\Corrections;

use Illuminate\Database\Eloquent\Model;

/**
 * Everything the service worked out about one correction, before writing
 * anything: the dates, the basis, the route, the blockers, the effect lines.
 * Built the same way for a preview, a submit (under the lock) and an approval
 * (under the lock, from the request's stored intent).
 */
final readonly class CorrectionPlan
{
    /**
     * @param  list<Blocker>  $blockers  hard blockers only
     * @param  list<Blocker>  $requestable  blockers a request to the Owner resolves
     * @param  list<EffectLine>  $effects
     * @param  list<string>  $stays
     * @param  list<string>  $warnings
     * @param  list<int>  $excluded  the original's maker and last editor
     * @param  list<string>  $duplicateIds  "type:id" of the candidates found now
     */
    public function __construct(
        public Model $document,
        public string $number,
        public string $fingerprint,
        public ?ReplacementData $replacement,
        public CorrectionDates $dates,
        public int $originalMinor,
        public ?int $successorMinor,
        public int $basisMinor,
        public ApprovalRoute $route,
        public array $blockers,
        public array $requestable,
        public array $effects,
        public array $stays,
        public array $warnings,
        public array $excluded,
        public ?DuplicateWarning $duplicateWarning,
        public ?DuplicateAck $duplicateAck,
        public array $duplicateIds,
        public int $plannedClosedJournals,
        /** ⭐ 24 Oct 2026: null = exactly `plannedClosedJournals`; else the least (CountsClosedPeriodJournals). */
        public ?int $minimumClosedJournals = null,
    ) {}
}
