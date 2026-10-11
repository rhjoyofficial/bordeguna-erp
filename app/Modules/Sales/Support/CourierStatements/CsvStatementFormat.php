<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * What every courier's CSV has in common: a header row, one parcel per row, a
 * consignment id that appears once.
 *
 * ⚠⚠ ONLY THE COLUMNS A FORMAT ASKS FOR ARE EVER READ. A courier's file carries
 * the recipient's name and phone; no format asks for them, so they never leave
 * the uploaded bytes (which are kept on the private disk as evidence, ChatGPT
 * risk 7, and nowhere else).
 *
 * ⚠ Headers are matched ignoring case and surrounding spaces, because a courier
 * that changes "COD_fee" to "COD_Fee" has not changed what the column means.
 */
abstract class CsvStatementFormat implements CourierStatementFormat
{
    /** @return list<string> the columns the file must have */
    abstract protected function required(): array;

    abstract protected function consignmentColumn(): string;

    /**
     * @param  callable(string): string  $cell  the trimmed value of a required column
     *
     * @throws StatementRowException|InvalidArgumentException
     */
    abstract protected function line(int $rowNumber, string $consignment, callable $cell): ParsedStatementLine;

    /** The column holding the courier's invoice id, when the file has one. */
    protected function referenceColumn(): ?string
    {
        return null;
    }

    public function parse(string $contents): ParsedStatement
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? '';
        $rows = array_values(array_filter(
            array_map('str_getcsv', preg_split('/\r\n|\n|\r/', $contents) ?: []),
            fn (array $row) => ! (count($row) === 1 && trim((string) $row[0]) === ''),
        ));

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($rows));
        $index = array_flip($header);
        $missing = array_values(array_filter($this->required(), fn (string $column) => ! isset($index[strtolower($column)])));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => "This is not a {$this->name()}: it has no ".implode(', ', $missing).' column.',
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file has a header and no parcels.']);
        }

        $allowed = array_flip(array_map('strtolower', $this->required()));
        $lines = [];
        $errors = [];
        $seen = [];
        $references = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 2;
            $cell = function (string $column) use ($row, $index, $allowed): string {
                $key = strtolower($column);

                if (! isset($allowed[$key])) {
                    throw new InvalidArgumentException("Column {$column} is not one this format reads.");
                }

                return trim((string) ($row[$index[$key]] ?? ''));
            };

            $number = $cell($this->consignmentColumn());

            if ($number === '') {
                $errors[] = ['row' => $rowNumber, 'consignment' => null, 'message' => 'The row has no consignment id.'];

                continue;
            }

            if (isset($seen[strtoupper($number)])) {
                $errors[] = ['row' => $rowNumber, 'consignment' => $number, 'message' => "Consignment {$number} appears twice in the file (rows {$seen[strtoupper($number)]} and {$rowNumber})."];

                continue;
            }

            $seen[strtoupper($number)] = $rowNumber;

            try {
                $lines[] = $this->line($rowNumber, $number, $cell);
            } catch (StatementRowException|InvalidArgumentException $e) {
                $errors[] = ['row' => $rowNumber, 'consignment' => $number, 'message' => $e->getMessage()];

                continue;
            }

            if (($column = $this->referenceColumn()) !== null && ($reference = $cell($column)) !== '') {
                $references[$reference] = true;
            }
        }

        if (count($references) > 1) {
            throw ValidationException::withMessages([
                'file' => 'The file holds more than one invoice ('.implode(', ', array_keys($references)).'). Export each invoice on its own and import them one by one.',
            ]);
        }

        return new ParsedStatement($lines, $errors, array_key_first($references));
    }

    /**
     * @param  callable(string): string  $cell
     */
    protected static function money(callable $cell, string $column): Money
    {
        $value = $cell($column);

        try {
            return Money::ofTaka($value === '' ? '0' : $value);
        } catch (InvalidArgumentException) {
            throw new StatementRowException("{$column} \"{$value}\" is not an amount of money.");
        }
    }

    protected static function nullable(string $value): ?string
    {
        return in_array(strtoupper($value), ['', 'N/A', 'NULL', '-'], true) ? null : $value;
    }
}
