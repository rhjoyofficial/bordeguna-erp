<?php

namespace App\Modules\Foundation\Corrections;

/**
 * "Tk 5,000" or "Tk 1,023.40": money in a sentence, English digits.
 *
 * ⚠ Built from whole paisa with integer arithmetic, never through a float.
 */
final class Tk
{
    public static function of(int $minor): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);
        $whole = intdiv($minor, 100);
        $paisa = $minor % 100;

        return ($negative ? '-' : '').'Tk '.number_format($whole).($paisa === 0 ? '' : '.'.str_pad((string) $paisa, 2, '0', STR_PAD_LEFT));
    }

    /** "15 Oct 2026". */
    public static function date(\DateTimeInterface $date): string
    {
        return $date->format('j M Y');
    }
}
