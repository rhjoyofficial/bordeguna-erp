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
        /**
         * Whether a day in this band is an exception HR must review before
         * payroll may use it (Correction C15b).
         *
         * ⚠ **Stored, never inferred.** Bor de Guna's third band happens to be
         * the only one with no free occurrences, so "deducts from the first
         * occurrence" would identify it today — and would be wrong the moment a
         * band is configured with no free occurrences but a mild consequence,
         * or an exceptional band that does grant a couple. This is the same
         * lesson `ScheduleAssignment::is_temporary` learned: a property that
         * currently correlates with a derivable one is still a different fact,
         * and conflating them mislabels the history later.
         *
         * ⚠ Defaults to **false** so an existing policy's behaviour is
         * unchanged. Bands 1 and 2 also deduct once their free occurrences are
         * exhausted, and Joy's ruling (2026-07-29) is that those are
         * deterministic policy outcomes needing no human judgement. Only a band
         * explicitly marked here is held back.
         */
        public bool $requiresReview = false,
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
            (bool) ($config['requires_review'] ?? false),
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
            'requires_review' => $this->requiresReview,
        ];
    }
}
