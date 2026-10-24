<?php

namespace App\Modules\Inventory\Enums;

/**
 * ⭐ Initial plan B4 (Joy, 24 Oct 2026; R17): what kind of raw material an item
 * is. "Item Group: Raw Material or Packaging Material."
 *
 * ⚠ A sub-kind of ProductType::RawMaterial, deliberately NOT a fourth product
 * type: every stock, purchase, recipe and costing path matches on
 * `type === RawMaterial`, and a new case there would break each of them
 * (the shared enum seam). Packaging is bought, stocked and consumed exactly as
 * any raw material; it differs only in where it is listed (the recipe's
 * Packaging section, the SKU's PK- family) and in never being "made here".
 */
enum MaterialKind: string
{
    case Raw = 'raw';
    case Packaging = 'packaging';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Raw material',
            self::Packaging => 'Packaging',
        };
    }
}
