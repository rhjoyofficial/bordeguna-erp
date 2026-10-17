<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Exceptions\UnitNotConvertibleException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a quantity typed in one unit into the quantity of a product's STOCK
 * unit, and says which factor did it.
 *
 * ⭐ 17 Oct 2026 (Phase 1, Part B; ChatGPT REPLY-1 Q2/Q3). Three paths, tried
 * in this order, and nothing else:
 *  1. the same unit: factor 1;
 *  2. the same mass or volume dimension: the GLOBAL base factors on the two
 *     units (g = 1, kg = 1000; mL = 1, L = 1000). Nobody types these;
 *  3. anything else (kg into L, jar into pcs, g into jar): a product's own
 *     conversion row, with its source and effective date, recorded in THAT
 *     direction. ⚠⚠ The reverse is never derived: mustard oil's 1 kg = 1.1 L
 *     and the old 1 L = 0.9 kg are not reciprocals, and an engine that quietly
 *     inverted one would drift 1% on every run.
 * No path: refused, never defaulted to 1 (the C-UOM-9 rule).
 *
 * ⚠⚠ DECIMAL ARITHMETIC (bcmath), never float: the result becomes a stock
 * quantity that FIFO consumes and a standard that variances are measured
 * against. Rounded ONCE, half up, to the four places a quantity column holds.
 * A positive quantity that rounds to zero is refused. A count stock unit
 * takes whole numbers unless the product allows fractions.
 */
final class UnitConverter
{
    /** Quantities are decimal(15,4). */
    public const QUANTITY_SCALE = 4;

    /** Factors are decimal(20,8). */
    public const FACTOR_SCALE = 8;

    /** Enough headroom that the rounding at the end is the only rounding. */
    private const WORKING_SCALE = 16;

    public const ONE = '1.00000000';

    public function toStockUnit(
        Product $product,
        string $quantity,
        ?UnitOfMeasure $enteredUnit = null,
        ?Carbon $on = null,
    ): UnitConversionResult {
        $stockUnit = $product->unitOfMeasure;
        $enteredUnit ??= $stockUnit;
        $quantity = self::clean($quantity);

        if (bccomp($quantity, '0', self::WORKING_SCALE) <= 0) {
            throw UnitNotConvertibleException::notPositive($product, $quantity, $enteredUnit);
        }

        [$factor, $basis, $conversionId] = $this->factor($product, $enteredUnit, $stockUnit, $on ?? Carbon::today());

        $rounded = self::apply($quantity, $factor);

        if (bccomp($rounded, '0', self::QUANTITY_SCALE) === 0) {
            throw UnitNotConvertibleException::roundsToZero($product, $quantity, $enteredUnit, $stockUnit);
        }

        if ($stockUnit->isCount() && ! $product->allows_fractional_stock && ! self::isWhole($rounded)) {
            throw UnitNotConvertibleException::notWhole($product, $rounded, $stockUnit);
        }

        return new UnitConversionResult(
            quantity: $rounded,
            factor: $factor,
            enteredQuantity: self::roundHalfUp($quantity, self::QUANTITY_SCALE),
            enteredUnitId: $enteredUnit->id,
            stockUnitId: $stockUnit->id,
            basis: $basis,
            conversionId: $conversionId,
        );
    }

    /**
     * The factor (stock units per one entered unit) and where it came from.
     *
     * @return array{0: string, 1: string, 2: ?int} [factor, basis, conversion id]
     *
     * @throws UnitNotConvertibleException when no path exists
     */
    public function factor(Product $product, UnitOfMeasure $from, UnitOfMeasure $to, Carbon $on): array
    {
        if ($from->id === $to->id) {
            return [self::ONE, 'same_unit', null];
        }

        $global = self::globalFactor($from, $to);

        if ($global !== null) {
            return [$global, 'global', null];
        }

        $row = ProductUnitConversion::forRecipeOn($product->id, $from->id, $to->id, $on);

        if ($row === null) {
            throw UnitNotConvertibleException::noPath($product, $from, $to);
        }

        return [self::roundHalfUp((string) $row->stock_units_per_purchase_unit, self::FACTOR_SCALE), 'product', $row->id];
    }

    /**
     * The units a line for this product may be written in: its stock unit,
     * every unit of the same mass or volume dimension, and every unit a
     * product row in force on the date converts from. Keyed by unit id, each
     * with the factor the screen may use for a PREVIEW (the server converts
     * again on save).
     *
     * @return Collection<int, array{unit: UnitOfMeasure, factor: string, basis: string}>
     */
    public function convertibleUnits(Product $product, Carbon $on, ?Collection $allUnits = null): Collection
    {
        $stockUnit = $product->unitOfMeasure;
        $allUnits ??= UnitOfMeasure::query()->orderBy('name')->get();

        $options = collect([$stockUnit->id => ['unit' => $stockUnit, 'factor' => self::ONE, 'basis' => 'same_unit']]);

        foreach ($allUnits as $unit) {
            if ($unit->id === $stockUnit->id) {
                continue;
            }

            $global = self::globalFactor($unit, $stockUnit);

            if ($global !== null) {
                $options[$unit->id] = ['unit' => $unit, 'factor' => $global, 'basis' => 'global'];
            }
        }

        $byId = $allUnits->keyBy('id');

        foreach (ProductUnitConversion::recipeRowsInForce($product->id, $stockUnit->id, $on) as $row) {
            $unit = $byId[$row->from_unit_id] ?? null;

            if ($unit !== null && ! $options->has($unit->id)) {
                $options[$unit->id] = [
                    'unit' => $unit,
                    'factor' => self::roundHalfUp((string) $row->stock_units_per_purchase_unit, self::FACTOR_SCALE),
                    'basis' => 'product',
                ];
            }
        }

        return $options;
    }

    /**
     * Stock units per one `$from`, when both are mass or both are volume and
     * both carry a base factor; null otherwise. ⚠ Count units never answer
     * here, whatever their dimension says: see UnitDimension.
     */
    public static function globalFactor(UnitOfMeasure $from, UnitOfMeasure $to): ?string
    {
        if ($from->id === $to->id) {
            return self::ONE;
        }

        $fromBase = self::baseFactor($from);
        $toBase = self::baseFactor($to);

        if ($fromBase === null || $toBase === null || $fromBase['dimension'] !== $toBase['dimension']) {
            return null;
        }

        return self::roundHalfUp(bcdiv($fromBase['factor'], $toBase['factor'], self::WORKING_SCALE), self::FACTOR_SCALE);
    }

    /**
     * The unit's dimension and its size in that dimension's base unit (g or
     * mL), or null when the unit is a count unit, an unknown unit, or has no
     * factor. The yield check in RecordProductionRun reads this.
     *
     * @return array{dimension: string, factor: string}|null
     */
    public static function baseFactor(?UnitOfMeasure $unit): ?array
    {
        if ($unit === null || $unit->factor_to_base === null) {
            return null;
        }

        if (! in_array($unit->dimension, [UnitDimension::MASS, UnitDimension::VOLUME], true)) {
            return null;
        }

        return ['dimension' => $unit->dimension, 'factor' => (string) $unit->factor_to_base];
    }

    /**
     * `quantity * factor`, rounded half up to four places. No refusals: the
     * waste on a production line goes through here with the line's own frozen
     * factor, and zero waste is a fine answer.
     */
    public static function apply(string $quantity, string $factor): string
    {
        return self::roundHalfUp(bcmul(self::clean($quantity), $factor, self::WORKING_SCALE), self::QUANTITY_SCALE);
    }

    /**
     * Half up at `$scale` places for a NON-NEGATIVE value, because bcmath
     * truncates and a truncated recipe quantity is a smaller quantity than the
     * one typed. Quantities here are never negative; a negative is refused
     * before it reaches this.
     */
    public static function roundHalfUp(string $value, int $scale): string
    {
        $value = self::clean($value);
        $half = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, $half, $scale);
    }

    public static function isWhole(string $quantity): bool
    {
        return bccomp($quantity, bcadd($quantity, '0', 0), self::QUANTITY_SCALE) === 0;
    }

    /** A number as bcmath wants it: trimmed, no exponent, no thousands separators. */
    private static function clean(string $value): string
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return '0';
        }

        // bcmath refuses "1e3"; number_format writes it out in full.
        if (stripos($value, 'e') !== false) {
            return number_format((float) $value, self::WORKING_SCALE, '.', '');
        }

        return $value;
    }
}
