<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\UnitOfMeasure;

/**
 * Which dimension a unit measures and how many base units one of it holds,
 * for the four units whose relation is a fact of the metric system and needs
 * no measuring: gram and kilogram (mass), millilitre and litre (volume).
 *
 * ⚠⚠ SAME DIMENSION ONLY. 1 kg is 1000 g and 1 L is 1000 mL by definition; a
 * kilogram is never turned into litres, because that needs a density nobody
 * measured (C-MFG-2). Every other unit (pieces, jars, boxes) answers null and
 * keeps today's rule: comparable only with itself.
 *
 * ⚠ units_of_measure carries only a name and an abbreviation, typed by hand and
 * by the catalogue import ("gm", "Kg", "Ltr"), so the unit is recognised by the
 * units seeder's aliases against both, lowercased. "ml" and "mL" are the same
 * unit; "l" is the litre.
 */
class UnitDimension
{
    public const MASS = 'mass';

    public const VOLUME = 'volume';

    private const MILLILITRE_ALIASES = ['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters'];

    private const LITRE_ALIASES = ['l', 'lt', 'ltr', 'litre', 'litres', 'liter', 'liters', 'লিটার'];

    /**
     * The unit's dimension and its size in that dimension's base unit (gram or
     * millilitre), or null when the unit is not one of the four.
     *
     * @return array{dimension: string, factor: int}|null
     */
    public static function of(?UnitOfMeasure $unit): ?array
    {
        if ($unit === null) {
            return null;
        }

        $keys = [mb_strtolower(trim((string) $unit->abbreviation)), mb_strtolower(trim((string) $unit->name))];

        return match (true) {
            array_intersect($keys, ProductWeight::GRAM_ALIASES) !== [] => ['dimension' => self::MASS, 'factor' => 1],
            array_intersect($keys, ProductWeight::KILOGRAM_ALIASES) !== [] => ['dimension' => self::MASS, 'factor' => 1000],
            array_intersect($keys, self::MILLILITRE_ALIASES) !== [] => ['dimension' => self::VOLUME, 'factor' => 1],
            array_intersect($keys, self::LITRE_ALIASES) !== [] => ['dimension' => self::VOLUME, 'factor' => 1000],
            default => null,
        };
    }
}
