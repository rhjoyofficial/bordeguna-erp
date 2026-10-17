<?php

namespace App\Modules\Foundation\Corrections;

use Illuminate\Support\Carbon;

/**
 * The Owner's one-document exception for a closed month, handed down the call
 * chain of ONE correction (PHASE-0-CONTRACT v2, 1.10).
 *
 * ⚠⚠ ONLY A HANDLE (v2, C5). PHP cannot keep a constructor private from another
 * class, so this object proves nothing by existing. What it may do is decided at
 * every use by the persisted closed_period_exceptions row (status, business,
 * original, action, dates, maximum journals, the granting Owner) and by the
 * secret, whose hash only that row holds. A structural test allows
 * `new ClosedPeriodPass` only inside ClosedPeriodExceptions.php.
 *
 * ⚠ An ARGUMENT, never ambient state: no static, no container binding. A
 * listener that posts some other journal in the same request cannot pick it up.
 */
final readonly class ClosedPeriodPass
{
    /** @param list<string> $dates Y-m-d */
    public function __construct(
        public int $exceptionId,
        public int $businessId,
        public string $originalType,
        public int $originalId,
        public string $action,
        public ?string $successorType,
        public array $dates,
        public int $maxJournals,
        public string $secret,
    ) {}

    /** The date is one this exception names, for this business. Consumption is checked separately. */
    public function covers(Carbon $date, ?int $businessId): bool
    {
        return $businessId === $this->businessId && in_array($date->toDateString(), $this->dates, true);
    }
}
