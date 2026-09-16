<?php

namespace App\Modules\Assets\Enums;

/**
 * How an item is counted (plan §2.2).
 *
 * - individual: one thing, its own QR tag, its own history (a laptop, a chair)
 * - quantity:   a pool of identical small things under one tag, counted
 *               (two pairs of scissors, a box of rulers)
 */
enum AssetTracking: string
{
    case Individual = 'individual';
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Each one tagged',
            self::Quantity => 'Counted as a group',
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
