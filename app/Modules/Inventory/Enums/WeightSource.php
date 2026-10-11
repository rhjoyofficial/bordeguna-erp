<?php

namespace App\Modules\Inventory\Enums;

/**
 * Where a product's weight came from (courier hub slice 3, 11 Oct 2026).
 *
 * ⚠ `Learned` is reserved: a later slice learns packed weight from courier hub
 * weights on single-product parcels and SUGGESTS it; the owner accepts. Nothing
 * writes it yet.
 */
enum WeightSource: string
{
    case Manual = 'manual';
    case FromNetContent = 'from_net_content';
    case Learned = 'learned';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Typed in',
            self::FromNetContent => 'From net content',
            self::Learned => 'Learned from courier weights',
        };
    }
}
