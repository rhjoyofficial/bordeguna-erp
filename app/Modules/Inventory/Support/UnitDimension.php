<?php

namespace App\Modules\Inventory\Support;

/**
 * What a unit of measure MEASURES, and how many base units one of it holds:
 * gram and kilogram (mass, base g), millilitre and litre (volume, base mL),
 * and the everyday count units (pieces, boxes, jars, packs, bottles, packets).
 *
 * ⭐ 17 Oct 2026 (Phase 1, Part B). This used to answer only the yield check,
 * from the unit's NAME every time it was asked. Now `units_of_measure` carries
 * `dimension` and `factor_to_base` as columns, and this class is the one
 * place that knows which typed-in name means which unit: the model fills the
 * two columns from it when a unit is created, the units seeder and the
 * migration backfill read it, and nothing else recognises a unit by name.
 *
 * ⚠⚠ SAME DIMENSION ONLY, and NEVER across count units. 1 kg is 1000 g and
 * 1 L is 1000 mL by definition; a kilogram is never turned into litres,
 * because that needs a density nobody measured (C-MFG-2), and a jar is never
 * turned into pieces, because "how many pieces in a jar" is a fact about one
 * product, not about the unit (ChatGPT REPLY-1, Q2). Those two conversions
 * exist only as a sourced, dated row on `product_unit_conversions`.
 *
 * ⚠ units_of_measure carries names typed by hand and by the catalogue import
 * ("gm", "Kg", "Ltr", "Piece"), so a unit is recognised by alias against both
 * the name and the abbreviation, lowercased. "ml" and "mL" are the same unit;
 * "l" is the litre.
 */
class UnitDimension
{
    public const MASS = 'mass';

    public const VOLUME = 'volume';

    public const COUNT = 'count';

    public const DIMENSIONS = [self::MASS, self::VOLUME, self::COUNT];

    private const MILLILITRE_ALIASES = ['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters', 'মিলি'];

    private const LITRE_ALIASES = ['l', 'lt', 'ltr', 'litre', 'litres', 'liter', 'liters', 'লিটার'];

    /**
     * The everyday units, as the units seeder plants them:
     * abbreviation => [name, aliases, dimension, factor_to_base].
     *
     * ⚠ A count unit has NO factor. "pcs" is the base of nothing: a box of 12
     * and a box of 24 are both "box", so the factor is a product fact and
     * lives on a product conversion row, never here.
     *
     * @var array<string, array{0: string, 1: list<string>, 2: string, 3: ?string}>
     */
    public const CATALOGUE = [
        'pcs' => ['Pieces', ['pcs', 'pc', 'pce', 'piece', 'pieces', 'nos', 'পিস'], self::COUNT, null],
        'kg' => ['Kilogram', ProductWeight::KILOGRAM_ALIASES, self::MASS, '1000'],
        'g' => ['Gram', ProductWeight::GRAM_ALIASES, self::MASS, '1'],
        'L' => ['Litre', self::LITRE_ALIASES, self::VOLUME, '1000'],
        'mL' => ['Millilitre', self::MILLILITRE_ALIASES, self::VOLUME, '1'],
        'box' => ['Box', ['box', 'boxes', 'bx', 'বক্স'], self::COUNT, null],
        'jar' => ['Jar', ['jar', 'jars', 'জার'], self::COUNT, null],
        'pack' => ['Pack', ['pack', 'packs', 'pk', 'প্যাক'], self::COUNT, null],
        'bottle' => ['Bottle', ['bottle', 'bottles', 'btl', 'বোতল'], self::COUNT, null],
        'packet' => ['Packet', ['packet', 'packets', 'pkt', 'প্যাকেট'], self::COUNT, null],
    ];

    /**
     * The dimension and base factor a typed-in unit name means, or null when
     * the name is not one this class knows ("dozen", "sack"): such a unit
     * converts into nothing by itself and needs a product row for everything.
     *
     * @return array{dimension: string, factor: ?string}|null
     */
    public static function recognise(?string $name, ?string $abbreviation): ?array
    {
        $keys = array_filter([
            mb_strtolower(trim((string) $abbreviation)),
            mb_strtolower(trim((string) $name)),
        ], fn (string $key) => $key !== '');

        if ($keys === []) {
            return null;
        }

        foreach (self::CATALOGUE as [, $aliases, $dimension, $factor]) {
            if (array_intersect($keys, $aliases) !== []) {
                return ['dimension' => $dimension, 'factor' => $factor];
            }
        }

        return null;
    }
}
