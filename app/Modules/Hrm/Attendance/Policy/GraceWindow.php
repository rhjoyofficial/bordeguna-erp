<?php

namespace App\Modules\Hrm\Attendance\Policy;

use App\Modules\Hrm\Enums\AttendanceConsequence;

/**
 * A band of lateness with its own monthly occurrence ladder (POL-03).
 *
 * The worked policy has two: arrivals up to 15 minutes late escalate over five
 * occurrences, while arrivals from 15 to 150 minutes late escalate far faster
 * over four. A single "grace minutes → late" threshold cannot express that,
 * which is why lateness is modelled as ordered windows each carrying its own
 * counter and ladder.
 *
 * Bounds are minutes after the shift's start time, exclusive of the lower bound
 * and inclusive of the upper — so 09:00:01 falls in a window starting at 0, and
 * 09:15:00 is still the first window while 09:15:01 is the second.
 */
final readonly class GraceWindow
{
    /**
     * @param  array<int, OccurrenceTier>  $tiers
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $fromMinutes,
        public ?int $toMinutes,
        public array $tiers,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['key'],
            $config['label'],
            (int) $config['from_minutes'],
            isset($config['to_minutes']) && $config['to_minutes'] !== null ? (int) $config['to_minutes'] : null,
            array_map(OccurrenceTier::fromArray(...), $config['tiers'] ?? []),
        );
    }

    /**
     * Whether an arrival this many minutes late falls in this window.
     */
    public function covers(int $lateMinutes): bool
    {
        if ($lateMinutes <= $this->fromMinutes) {
            return false;
        }

        return $this->toMinutes === null || $lateMinutes <= $this->toMinutes;
    }

    /**
     * The consequence for the Nth occurrence in the month — the highest tier
     * whose floor this occurrence has reached. No tier means no consequence.
     */
    public function consequenceFor(int $occurrence): AttendanceConsequence
    {
        $consequence = AttendanceConsequence::None;

        foreach ($this->tiers as $tier) {
            if ($occurrence >= $tier->fromOccurrence) {
                $consequence = $tier->consequence;
            }
        }

        return $consequence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'from_minutes' => $this->fromMinutes,
            'to_minutes' => $this->toMinutes,
            'tiers' => array_map(fn (OccurrenceTier $tier) => $tier->toArray(), $this->tiers),
        ];
    }
}
