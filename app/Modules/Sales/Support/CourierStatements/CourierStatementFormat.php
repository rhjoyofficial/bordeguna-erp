<?php

namespace App\Modules\Sales\Support\CourierStatements;

use Illuminate\Validation\ValidationException;

/**
 * How one courier writes its payout statement. Pathao's is the first; another
 * courier arrives as another implementation, and nothing that posts a statement
 * changes (Joy, 13 September: Pathao today, others soon).
 */
interface CourierStatementFormat
{
    public function name(): string;

    /**
     * @return list<ParsedStatementLine>
     *
     * @throws ValidationException naming the row, when the file is not what the courier writes
     */
    public function parse(string $contents): array;
}
