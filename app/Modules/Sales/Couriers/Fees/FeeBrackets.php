<?php

namespace App\Modules\Sales\Couriers\Fees;

/**
 * The weight brackets the history and the variance report group parcels by:
 * the breaks both couriers' tables share (0.5, 1, 2, 3, 5 kg). A rate card
 * keeps its own brackets; these are for counting and comparing.
 */
final class FeeBrackets
{
    /** @var list<array{0: int, 1: int|null}> */
    public const BREAKS = [[0, 500], [500, 1000], [1000, 2000], [2000, 3000], [3000, 5000], [5000, null]];

    public static function for(int $grams): string
    {
        foreach (self::BREAKS as [$from, $to]) {
            if ($grams > $from && ($to === null || $grams <= $to)) {
                return self::label($from, $to);
            }
        }

        return self::label(0, 500);
    }

    /** @return list<string> */
    public static function labels(): array
    {
        return array_map(fn (array $b) => self::label($b[0], $b[1]), self::BREAKS);
    }

    public static function label(int $from, ?int $to): string
    {
        return $to === null ? "{$from}+ g" : "{$from}-{$to} g";
    }

    /** "1.2 kg", "0.45 kg". */
    public static function kg(int $grams): string
    {
        return rtrim(rtrim(number_format($grams / 1000, 3, '.', ''), '0'), '.').' kg';
    }

    /** "Tk 90", "Tk 98.50". */
    public static function taka(float $amount): string
    {
        return 'Tk '.(fmod(round($amount, 2), 1.0) === 0.0 ? number_format($amount, 0) : number_format($amount, 2));
    }

    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return round($count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 2);
    }
}
