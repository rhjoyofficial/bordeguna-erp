<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\UnitOfMeasure;

/**
 * A product's weight from its net content, when the net content is a mass.
 *
 * ⚠⚠ MASS ONLY. 400 g net is a 400 g weight to start from; 5 L is NOT turned
 * into kilograms through a density nobody measured. A liquid stays empty and
 * shows "weight missing" until somebody weighs one (Joy, 11 Oct 2026).
 *
 * The aliases are the units seeder's, because production's units were typed
 * by hand ("gm", "Kg") and by the catalogue import.
 */
class ProductWeight
{
    public const GRAM_ALIASES = ['g', 'gm', 'gms', 'gr', 'grm', 'gram', 'grams', 'গ্রাম'];

    public const KILOGRAM_ALIASES = ['kg', 'kgs', 'kilo', 'kilogram', 'kilograms', 'কেজি'];

    /** The heaviest single stock unit a weight may claim: 1,000 kg. */
    public const MAX_GRAMS = 1_000_000;

    /** Grams per one of this unit, or null when the unit is not a mass. */
    public static function gramsPerUnit(?UnitOfMeasure $unit): ?int
    {
        if ($unit === null) {
            return null;
        }

        $keys = [mb_strtolower(trim((string) $unit->abbreviation)), mb_strtolower(trim((string) $unit->name))];

        return match (true) {
            array_intersect($keys, self::GRAM_ALIASES) !== [] => 1,
            array_intersect($keys, self::KILOGRAM_ALIASES) !== [] => 1000,
            default => null,
        };
    }

    /** Whole grams from a net content, or null when it cannot honestly be said. */
    public static function fromNetContent(mixed $quantity, ?UnitOfMeasure $unit): ?int
    {
        $factor = self::gramsPerUnit($unit);

        if ($factor === null || $quantity === null || $quantity === '' || ! is_numeric($quantity)) {
            return null;
        }

        $grams = (int) round((float) $quantity * $factor);

        return $grams > 0 && $grams <= self::MAX_GRAMS ? $grams : null;
    }
}
