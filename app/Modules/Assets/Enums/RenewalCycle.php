<?php

namespace App\Modules\Assets\Enums;

/**
 * How often an account renews. Recorded beside the date so the hub can say
 * "yearly, Tk 1,800" rather than leaving the reader to work it out.
 */
enum RenewalCycle: string
{
    case None = 'none';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Does not renew',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
            self::Other => 'Other',
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
