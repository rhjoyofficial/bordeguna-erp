<?php

namespace App\Modules\Hrm\Attendance\Data;

use Illuminate\Support\Carbon;

/**
 * One in/out span. An `out` of null means the pair never closed — which is a
 * missing punch (POL-07), not a reason to guess an end time.
 */
final readonly class PunchPair
{
    public function __construct(
        public Carbon $in,
        public ?Carbon $out,
    ) {}

    public function isClosed(): bool
    {
        return $this->out !== null;
    }

    /**
     * Whole minutes worked. Floored deliberately: a partial minute is not
     * credited, and flooring keeps the figure stable rather than letting a
     * stray second round a day up.
     */
    public function minutes(): int
    {
        if ($this->out === null) {
            return 0;
        }

        return (int) max(0, floor($this->in->diffInSeconds($this->out) / 60));
    }
}
