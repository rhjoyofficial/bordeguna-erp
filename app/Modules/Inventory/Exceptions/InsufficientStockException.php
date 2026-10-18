<?php

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use RuntimeException;
use Throwable;

class InsufficientStockException extends RuntimeException
{
    /**
     * ⚠ P10 (18 Oct 2026, practice run): this read "Cannot move 20000 units of
     * product #6 from warehouse #4: only 5000 available." Ids mean nothing at
     * the counter or on the production floor, and "units" hid that the numbers
     * were grams. Every screen that shows this refusal (production run, counter,
     * delivery, transfer, adjustment, staff purchase) now gets the product and
     * warehouse by name, the stock unit, and grouped numbers.
     */
    public static function forProductInWarehouse(int $productId, int $warehouseId, float $requested, float $available): self
    {
        [$product, $unit] = self::productLabel($productId);
        $warehouse = self::warehouseLabel($warehouseId);
        $suffix = $unit === null ? '' : ' '.$unit;

        return new self(
            "Not enough {$product} in {$warehouse}: needs ".self::grouped($requested).$suffix
            .', only '.self::grouped($available).$suffix.' available.'
        );
    }

    /**
     * Traceability M2 (17 Oct 2026): the shortfall is stock ON HOLD. Said in
     * plain words with the product and the warehouse by name, because "only 0
     * available" beside a shelf of jars reads as a bug at the counter.
     */
    public static function heldStock(string $product, string $warehouse, float $requested, float $available, float $held): self
    {
        $free = $available > 0 ? ' Only '.self::number($available).' is free to use.' : ' Nothing else is free to use.';

        return new self(
            "{$product} in {$warehouse}: ".self::number($held).' is on hold and cannot be used.'
            .$free.' You asked for '.self::number($requested).'. Release the batch first, or take it from another warehouse.'
        );
    }

    /** @return array{0: string, 1: string|null} the name and the stock unit, or a fallback that still says which */
    private static function productLabel(int $productId): array
    {
        try {
            $product = Product::query()->with('unitOfMeasure:id,abbreviation')->find($productId);
        } catch (Throwable) {
            $product = null;
        }

        if ($product === null) {
            return ["product #{$productId}", null];
        }

        return [$product->name, $product->unitOfMeasure?->abbreviation];
    }

    private static function warehouseLabel(int $warehouseId): string
    {
        try {
            $name = Warehouse::query()->whereKey($warehouseId)->value('name');
        } catch (Throwable) {
            $name = null;
        }

        return $name ?? "warehouse #{$warehouseId}";
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /** `20000` -> `20,000`, `2.5` -> `2.5`: grouped, up to 4 places, never rounded to whole. */
    private static function grouped(float $value): string
    {
        $text = number_format($value, 4, '.', ',');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
