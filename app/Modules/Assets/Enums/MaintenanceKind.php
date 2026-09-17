<?php

namespace App\Modules\Assets\Enums;

/**
 * What kind of work a maintenance record is (slice A4).
 */
enum MaintenanceKind: string
{
    case Preventive = 'preventive';
    case Repair = 'repair';
    case Inspection = 'inspection';

    public function label(): string
    {
        return match ($this) {
            self::Preventive => 'Routine care',
            self::Repair => 'Repair',
            self::Inspection => 'Check-up',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
