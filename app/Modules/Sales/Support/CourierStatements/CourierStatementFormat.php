<?php

namespace App\Modules\Sales\Support\CourierStatements;

use Illuminate\Validation\ValidationException;

/**
 * How one courier writes its payout statement. Pathao's was the first, CarryBee's
 * the second (11 Oct 2026); another courier arrives as another implementation,
 * and nothing that posts a statement changes.
 */
interface CourierStatementFormat
{
    /** Shown to people: "Pathao invoice CSV". */
    public function name(): string;

    /** Stored on the statement: which reader read it, and which version. */
    public function code(): string;

    /**
     * ⚠ A row that cannot be read is returned as a row error, never thrown: the
     * rest of the file is still read (courier hub plan F.4).
     *
     * @throws ValidationException when the file as a whole is not what the courier writes
     */
    public function parse(string $contents): ParsedStatement;
}
