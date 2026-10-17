<?php

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use DomainException;

/**
 * A quantity typed in one unit cannot become a stock quantity in another.
 *
 * ⚠⚠ Every message here is a REFUSAL, never a default. The alternative on each
 * path - a factor of 1, a silent zero, a half jar - is a wrong number that
 * looks right with every ledger balancing (ChatGPT REPLY-1, Q2: "if a positive
 * quantity rounds to zero, refuse rather than silently consume zero").
 */
class UnitNotConvertibleException extends DomainException
{
    public static function noPath(Product $product, UnitOfMeasure $from, UnitOfMeasure $to): self
    {
        $hint = $from->dimension === $to->dimension && $from->dimension !== null && $from->dimension !== 'count'
            ? 'Both units need a base factor.'
            : "Record a recipe conversion for {$product->sku} (1 {$from->abbreviation} = ? {$to->abbreviation}, with its source) under Inventory > Setup > Purchase units, or write the line in {$to->abbreviation}.";

        return new self(
            "{$product->name} is stocked in {$to->abbreviation}, and the ERP has no way to turn {$from->abbreviation} into {$to->abbreviation} for it. {$hint}"
        );
    }

    public static function notPositive(Product $product, string $quantity, UnitOfMeasure $unit): self
    {
        return new self("{$product->name} needs a quantity greater than zero; got {$quantity} {$unit->abbreviation}.");
    }

    public static function roundsToZero(Product $product, string $quantity, UnitOfMeasure $from, UnitOfMeasure $to): self
    {
        return new self(
            "{$quantity} {$from->abbreviation} of {$product->name} is less than 0.00005 {$to->abbreviation}, which stock cannot hold: it would round to zero and consume nothing. ".
            'Type a larger quantity or write the line in a smaller unit.'
        );
    }

    public static function notWhole(Product $product, string $quantity, UnitOfMeasure $unit): self
    {
        return new self(
            "{$product->name} is counted in whole {$unit->abbreviation}, and {$quantity} {$unit->abbreviation} is not a whole number. ".
            'Round it, or allow fractional stock on the product if it really is split.'
        );
    }

    public static function unvalidatedFactor(Product $product): self
    {
        return new self(
            "The conversion factor on the {$product->name} line was never validated, so the run cannot consume it. Delete the draft and enter it again."
        );
    }
}
