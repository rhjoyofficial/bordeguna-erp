<?php

namespace App\Modules\Crm\Support;

use App\Modules\Crm\Models\Contact;
use App\Modules\Foundation\Support\CsvSafe;
use RuntimeException;

/**
 * The phone book file: what its columns are, how it is read, how it is written.
 *
 * ⚠⚠ ONE definition of the shape, used by the template, the export AND the
 * import, for the reason the customer and supplier files state: a template the
 * importer then refuses leaves the person holding it with no way to tell which
 * half is wrong.
 *
 * ⚠ Header problems are fatal rather than per-row errors. A file whose columns
 * are not understood produces a page of row errors that all say the same thing
 * badly.
 */
class ContactCsv
{
    /**
     * ⚠⚠ ONLY `name` IS MANDATORY, and unlike the customer file there is no
     * `code`. A phone book is typed by whoever collected the numbers and a
     * mandatory permanent key would stop the import that matters most: a
     * spreadsheet of names and numbers somebody already has.
     *
     * ⚠ The consequence is that re-importing the same file creates the same
     * people again unless the planner matches them, which is exactly why
     * matching is on `phone_normalized` and why it MATCHES rather than merges.
     */
    public const MANDATORY = ['name'];

    public const OPTIONAL = ['company', 'phone', 'email', 'address', 'source', 'note', 'is_active'];

    /** What each column is for, shown beside the template. */
    public const DESCRIPTIONS = [
        'name' => 'The person as you refer to them. Bangla is fine.',
        'company' => 'Their shop or firm, if they have one.',
        'phone' => 'Bangladesh mobile, with the leading zero. e.g. 01712345678',
        'email' => 'Optional. Must be a real address if given.',
        'address' => 'Free text, one line.',
        'source' => 'Where the number came from. e.g. Trade fair 2026, old register.',
        'note' => 'Anything worth remembering about this person.',
        'is_active' => 'active or inactive. Blank means active.',
    ];

    /**
     * CRM-007 (audit 14 Oct 2026). The columns anybody may type anything into,
     * so the ones a spreadsheet could run as a formula. Written through
     * CsvSafe::cell on export and read back through CsvSafe::read, the rule the
     * asset register exports already follow. `phone` is left alone on purpose:
     * it is returned exactly as typed (a leading + is a real number, and the
     * importer refuses anything that is not one).
     */
    private const FREE_TEXT = ['name', 'company', 'address', 'source', 'note'];

    /** @return list<string> */
    public static function columns(): array
    {
        return array_merge(self::MANDATORY, self::OPTIONAL);
    }

    /**
     * A blank file with the right header.
     *
     * ⚠⚠ HEADER ONLY, no example row. A template carrying a sample person is a
     * template somebody imports unchanged, and then a fictional contact is in
     * the phone book for ever, and one day gets sent a message.
     */
    public static function template(): string
    {
        return self::toCsv([]);
    }

    /**
     * @param  iterable<Contact>  $contacts
     */
    public static function export(iterable $contacts): string
    {
        $rows = [];

        foreach ($contacts as $contact) {
            $row = [];

            foreach (self::columns() as $column) {
                $row[$column] = match ($column) {
                    // ⚠ Written back as the words the importer accepts, never as
                    // 1/0. An export that cannot be re-imported is not an
                    // export, and this file is meant to round-trip.
                    'is_active' => $contact->is_active ? 'active' : 'inactive',
                    // ⚠⚠ `phone`, never `phone_normalized`. The export must
                    // return what the human typed; handing back the matching
                    // form and re-importing it would overwrite everybody's real
                    // number with our tidied one.
                    default => in_array($column, self::FREE_TEXT, true)
                        ? (string) CsvSafe::cell((string) ($contact->{$column} ?? ''))
                        : (string) ($contact->{$column} ?? ''),
                };
            }

            $rows[] = $row;
        }

        return self::toCsv($rows);
    }

    /**
     * @param  list<array<string,string>>  $rows
     */
    public static function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // ⚠⚠ A UTF-8 BOM, on purpose. Without it Excel opens the file in the
        // system codepage and renders every Bangla name as mojibake, and the
        // person then "fixes" it by retyping.
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::columns());

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $c) => $row[$c] ?? '', self::columns()));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read contact file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open contact file: {$path}");
        }

        try {
            return self::readHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function readHandle($handle): array
    {
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            throw new RuntimeException('The contact file is empty.');
        }

        $records = (function () use ($handle) {
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                yield $line => $values;
            }
        })();

        return self::rowsFrom($header, $records);
    }

    /**
     * The half of reading that does not care what the file was: the header
     * rules, blank rows, short rows and the export's formula quote. The CSV
     * reader above and ContactSheet (Excel, 14 Oct 2026) both end here, so an
     * Excel file and a CSV file are refused and accepted by the same rules.
     *
     * @param  list<string|null>  $header
     * @param  iterable<int, list<string|null>>  $records  keyed by the line (or sheet row) number
     * @return list<array{line:int, values:array<string,string>}>
     */
    public static function rowsFrom(array $header, iterable $records): array
    {
        $header = self::normaliseHeader($header);
        self::assertHeaderIsUsable($header);

        $rows = [];

        foreach ($records as $line => $values) {
            if ($values === [null] || self::isBlankRow($values)) {
                continue;
            }

            // ⚠ Short rows are padded: a spreadsheet routinely drops trailing
            // empty cells, and every column after `name` is optional. A row with
            // MORE values than the header stays fatal, because then the columns
            // do not line up and every value is in the wrong field.
            if (count($values) > count($header)) {
                throw new RuntimeException(
                    "Line {$line} has ".count($values).' values but the header declares '.count($header).
                    '. Fix the file rather than guessing which column is which.'
                );
            }

            $values = array_pad($values, count($header), '');

            $rows[] = [
                'line' => $line,
                'values' => self::unquote(array_map(fn ($v) => trim((string) $v), array_combine($header, $values))),
            ];
        }

        return $rows;
    }

    /**
     * Takes off the quote the export put in front of a formula-looking cell
     * (CRM-007), so a file exported and re-imported says the same thing.
     *
     * @param  array<string,string>  $values
     * @return array<string,string>
     */
    private static function unquote(array $values): array
    {
        foreach (self::FREE_TEXT as $column) {
            if (isset($values[$column])) {
                $values[$column] = (string) CsvSafe::read($values[$column]);
            }
        }

        return $values;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private static function normaliseHeader(array $header): array
    {
        $clean = [];

        foreach ($header as $index => $name) {
            $name = (string) $name;

            // ⚠⚠ Excel writes a UTF-8 BOM, and so does our own export above.
            // Unstripped, the first column reads as "\u{FEFF}name" and the
            // mandatory-column check fails with a message that looks like a lie:
            // the two strings are identical in every terminal.
            if ($index === 0) {
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $clean[] = strtolower(trim($name));
        }

        return $clean;
    }

    /**
     * @param  list<string>  $header
     */
    private static function assertHeaderIsUsable(array $header): void
    {
        $missing = array_diff(self::MANDATORY, $header);

        if ($missing !== []) {
            throw new RuntimeException('The file is missing mandatory column(s): '.implode(', ', $missing).'.');
        }

        $duplicates = array_keys(array_filter(array_count_values($header), fn ($n) => $n > 1));

        if ($duplicates !== []) {
            throw new RuntimeException('The file repeats column(s): '.implode(', ', $duplicates).'.');
        }

        // ⚠⚠ An unknown column is an ERROR, not a warning. A file headed
        // `mobile` instead of `phone` imports perfectly and silently drops every
        // phone number, and nothing downstream can ever tell. In a phone book
        // that is the entire point of the file.
        $unknown = array_diff($header, self::MANDATORY, self::OPTIONAL);

        if ($unknown !== []) {
            throw new RuntimeException(
                'The file has unrecognised column(s): '.implode(', ', $unknown).
                '. Expected only: '.implode(', ', self::columns()).'.'
            );
        }
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
