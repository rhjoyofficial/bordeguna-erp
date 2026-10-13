<?php

namespace App\Modules\Assets\Support;

use App\Modules\Inventory\Support\CatalogueText;
use RuntimeException;

/**
 * Reads the accounts sheet (asset register slice 4): one row per account the
 * business owns, in exactly the template's columns.
 *
 * ⛔ NO PASSWORD COLUMN, BY DESIGN. A spreadsheet of passwords is the thing
 * this whole slice exists to end: logins are typed in the screen only, where
 * they are encrypted before they touch the disk.
 *
 * ⚠ Strict about columns for the product importer's reason: a column headed
 * `owner` (the first template's name for it) instead of `responsible` would
 * read as a file of blanks.
 */
class CompanyAccountFile
{
    public const MANDATORY = ['kind', 'name'];

    public const OPTIONAL = ['provider', 'identifier', 'url', 'registered_as', 'responsible', 'users', 'two_factor_holder', 'two_factor_method', 'renews_on', 'renewal_cycle', 'renewal_cost', 'status', 'recovery_email', 'recovery_phone', 'shared_with', 'note'];

    /**
     * The template's own order, which is also the export's.
     *
     * ⭐ Review of 13 October: `responsible` (was `owner`: the employee
     * accountable inside the company), `registered_as` (the identity the
     * account is registered under at the provider), `two_factor_method`,
     * `renewal_cycle`, and `shared_with` (`;`-separated slugs of the OTHER
     * businesses of the group the account also serves).
     */
    public const TEMPLATE = ['kind', 'name', 'provider', 'identifier', 'url', 'registered_as', 'responsible', 'users', 'two_factor_holder', 'two_factor_method', 'renews_on', 'renewal_cycle', 'renewal_cost', 'status', 'recovery_email', 'recovery_phone', 'shared_with', 'note'];

    /**
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read the accounts file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the accounts file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new RuntimeException('The accounts file is empty.');
            }

            $clean = [];

            foreach ($header as $index => $name) {
                $name = (string) $name;

                // ⚠⚠ Excel writes a UTF-8 BOM in front of the first column.
                if ($index === 0) {
                    $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
                }

                $clean[] = strtolower(CatalogueText::clean($name));
            }

            $header = $clean;
            $missing = array_diff(self::MANDATORY, $header);

            if ($missing !== []) {
                throw new RuntimeException('The file is missing column(s): '.implode(', ', $missing).'. Download the template and fill that in.');
            }

            $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

            if ($duplicates !== []) {
                throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
            }

            $unknown = array_diff($header, self::MANDATORY, self::OPTIONAL);

            if ($unknown !== []) {
                throw new RuntimeException(
                    'The file has unrecognised column(s): '.implode(', ', $unknown).'. Expected only: '.implode(', ', self::TEMPLATE).'.'
                );
            }

            $rows = [];
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                $values = array_map(fn ($value) => CatalogueText::clean((string) $value), $values);

                if (implode('', $values) === '') {
                    continue;
                }

                if (count($values) !== count($header)) {
                    throw new RuntimeException(
                        "Line {$line} has ".count($values).' values but the header declares '.count($header).
                        '. Fix the file rather than guessing which column is which.'
                    );
                }

                $rows[] = ['line' => $line, 'values' => array_combine($header, $values)];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
