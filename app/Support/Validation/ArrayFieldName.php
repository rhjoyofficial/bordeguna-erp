<?php

namespace App\Support\Validation;

use Illuminate\Support\Str;

/**
 * ⚠ P10 (18 Oct 2026, practice run): a refusal on a line of a form read "The
 * lines.0.product_id field is required." - the raw path the browser posted.
 * Every array field that has no name of its own now reads as words with a
 * counted position: `lines.0.product_id` -> "line 1 product",
 * `items.2.unit_price` -> "item 3 unit price". Installed once for every form
 * (AppServiceProvider), so a new form with line rules cannot leak the path.
 */
final class ArrayFieldName
{
    public static function forHumans(string $attribute): string
    {
        $segments = explode('.', $attribute);
        $words = [];

        foreach ($segments as $index => $segment) {
            if (ctype_digit($segment)) {
                // "lines" + 0 -> "line 1": the position, counted from one.
                $parent = array_pop($words) ?? 'row';
                $words[] = Str::singular($parent).' '.((int) $segment + 1);

                continue;
            }

            $isLast = $index === count($segments) - 1;
            $field = $isLast ? preg_replace('/_id$/', '', $segment) : $segment;
            $words[] = str_replace('_', ' ', Str::snake((string) $field));
        }

        return implode(' ', $words);
    }
}
