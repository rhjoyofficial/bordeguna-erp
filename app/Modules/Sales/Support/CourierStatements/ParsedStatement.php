<?php

namespace App\Modules\Sales\Support\CourierStatements;

/**
 * A courier's file as read: the parcels that could be read, the rows that could
 * not (row, consignment, why), and the courier's own invoice id when the file
 * carries one.
 */
final readonly class ParsedStatement
{
    /**
     * @param  list<ParsedStatementLine>  $lines
     * @param  list<array{row: int, consignment: ?string, message: string}>  $errors
     */
    public function __construct(
        public array $lines,
        public array $errors,
        public ?string $reference = null,
    ) {}
}
