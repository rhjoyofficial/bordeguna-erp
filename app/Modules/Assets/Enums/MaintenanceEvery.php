<?php

namespace App\Modules\Assets\Enums;

use Carbon\CarbonInterface;

/**
 * The unit a maintenance plan repeats in (slice A4).
 */
enum MaintenanceEvery: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(int $count): string
    {
        return $count === 1 ? "Every {$this->value}" : "Every {$count} {$this->value}s";
    }

    /**
     * ⚠ Months without overflow: a plan done on 31 January is next due on
     * 28 or 29 February, not 3 March.
     */
    public function after(CarbonInterface $date, int $count): CarbonInterface
    {
        return match ($this) {
            self::Day => $date->copy()->addDays($count),
            self::Week => $date->copy()->addWeeks($count),
            self::Month => $date->copy()->addMonthsNoOverflow($count),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => ucfirst($case->value).'s'], self::cases());
    }
}
