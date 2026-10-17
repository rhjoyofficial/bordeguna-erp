<?php

namespace App\Modules\Foundation\Corrections;

use Illuminate\Support\Carbon;

/**
 * The edited form of a Correct, validated by the type's own corrector with the
 * same rules as creating the document. `values` is whatever that type needs.
 */
final readonly class ReplacementData
{
    public function __construct(
        public Carbon $date,
        public int $totalMinor,
        public array $values,
        /** The raw, validated input, kept on a request so approval re-validates it. */
        public array $input,
    ) {}
}
