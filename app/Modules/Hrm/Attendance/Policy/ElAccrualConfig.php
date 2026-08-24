<?php

namespace App\Modules\Hrm\Attendance\Policy;

/**
 * The monthly earned-leave accrual rule (Joy, 24 Aug 2026): one EL day per
 * month to each PERMANENT employee, unless the month's lateness disqualifies
 * it - more than `maxOrdinaryLates` ordinary lates or more than
 * `maxSeriousLates` serious ones.
 *
 * Lives on the COMPANY-DEFAULT attendance policy's config so HR adjusts it
 * from the same screen as the ladders, versioned the same way. Department
 * policies may carry the keys but they are ignored - one accrual rule for the
 * business, or nobody can say what an employee who changed departments
 * mid-month earned.
 *
 * ⚠ Disabled by default so every existing policy row keeps meaning exactly
 * what it meant before this class existed; enabling it is a policy revision.
 */
final readonly class ElAccrualConfig
{
    public function __construct(
        public bool $enabled = false,
        public float $daysPerMonth = 1,
        public int $maxOrdinaryLates = 3,
        public int $maxSeriousLates = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (bool) ($config['enabled'] ?? false),
            (float) ($config['days_per_month'] ?? 1),
            (int) ($config['max_ordinary_lates'] ?? 3),
            (int) ($config['max_serious_lates'] ?? 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'days_per_month' => $this->daysPerMonth,
            'max_ordinary_lates' => $this->maxOrdinaryLates,
            'max_serious_lates' => $this->maxSeriousLates,
        ];
    }
}
