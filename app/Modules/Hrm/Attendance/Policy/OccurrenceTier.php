<?php

namespace App\Modules\Hrm\Attendance\Policy;

use App\Modules\Hrm\Enums\AttendanceConsequence;

/**
 * "From the Nth occurrence in a month, this is the consequence" — one rung of
 * the POL-03 escalation ladder.
 *
 * Tiers are expressed as a floor rather than an exact count so a ladder can end
 * open-ended ("from the 5th onward"), which is precisely how the worked policy
 * is written.
 */
final readonly class OccurrenceTier
{
    public function __construct(
        public int $fromOccurrence,
        public AttendanceConsequence $consequence,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (int) $config['from_occurrence'],
            AttendanceConsequence::from($config['consequence']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from_occurrence' => $this->fromOccurrence,
            'consequence' => $this->consequence->value,
        ];
    }
}
