<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Models\Supplier;
use RuntimeException;

/**
 * The supplier file: what its columns are, how it is read, and how it is written.
 *
 * ⚠⚠ ONE definition of the shape, used by the template, the export AND the
 * import. If the template and the reader disagreed by a single column name, the
 * file this system hands you would be a file it then refuses to accept - and
 * the person holding it has no way to tell which of the two is wrong.
 *
 * ⚠ Header problems are fatal rather than per-row errors, for the same reason
 * the catalogue importer treats them that way: a file whose columns are not
 * understood produces a page of row errors that all say the same thing badly.
 */
class SupplierCsv
{
    /** ⚠ `code` is the identity and is never derived; see SUPPLIER-CODE-PATTERN.md. */
    public const MANDATORY = ['code', 'name'];

    /**
     * ⚠ Everything else is optional on purpose. Ayesha Siddika is an individual:
     * her own name IS the supplier name and `contact_name` is deliberately
     * blank. A file that demanded a contact would have no way to express her.
     */
    public const OPTIONAL = [
        'contact_name', 'phone', 'email', 'address', 'payment_terms',
        'bin', 'tin', 'bank_name', 'bank_branch', 'bank_account_name',
        'bank_account_number', 'mfs_number', 'notes', 'is_active',
    ];

    /** What each column is for, shown by the template command. */
    public const DESCRIPTIONS = [
        'code' => 'PERMANENT identifier, uppercase, never reused. e.g. ADDEEN',
        'name' => 'The supplier as you refer to them. e.g. Ad-Deen Food',
        'contact_name' => 'Person to speak to. Leave blank when the supplier IS a person.',
        'phone' => 'Bangladesh mobile, with the leading zero. e.g. 01712345678',
        'email' => 'Optional. Must be a real address if given.',
        'address' => 'Free text, one line.',
        'payment_terms' => 'Free text for now. e.g. 30 days, Cash on delivery',
        'bin' => 'Business Identification Number (VAT). Optional.',
        'tin' => 'Taxpayer Identification Number. Optional.',
        'bank_name' => 'e.g. BRAC Bank',
        'bank_branch' => 'e.g. Adabor',
        'bank_account_name' => 'Name the account is held in.',
        'bank_account_number' => 'Kept as text so leading zeros survive.',
        'mfs_number' => 'bKash / Nagad number.',
        'notes' => 'Anything worth remembering about this supplier.',
        'is_active' => 'active or inactive. Blank means active.',
    ];

    /** @return list<string> */
    public static function columns(): array
    {
        return array_merge(self::MANDATORY, self::OPTIONAL);
    }

    /**
     * A blank file with the right header.
     *
     * ⚠⚠ HEADER ONLY, with NO example row, and that is deliberate. A template
     * carrying a sample supplier is a template somebody imports unchanged, and
     * then a fictional company is in the purchase ledger for ever. The command
     * prints the per-column guidance instead, where it cannot be imported.
     */
    public static function template(): string
    {
        return self::toCsv([]);
    }

    /**
     * @param  iterable<Supplier>  $suppliers
     */
    public static function export(iterable $suppliers): string
    {
        $rows = [];

        foreach ($suppliers as $supplier) {
            $row = [];

            foreach (self::columns() as $column) {
                $row[$column] = match ($column) {
                    // ⚠ Written back as the words the importer accepts, not as
                    // 1/0. An export that cannot be re-imported is not an
                    // export, and this file is meant to round-trip.
                    'is_active' => $supplier->is_active ? 'active' : 'inactive',
                    default => (string) ($supplier->{$column} ?? ''),
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
        // system codepage and renders every Bangla supplier name as mojibake -
        // and the person then "fixes" it by retyping, which is how good data
        // gets destroyed by a tool that was only supposed to show it.
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
            throw new RuntimeException("Cannot read supplier file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open supplier file: {$path}");
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
            throw new RuntimeException('The supplier file is empty.');
        }

        $header = self::normaliseHeader($header);
        self::assertHeaderIsUsable($header);

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if ($values === [null] || self::isBlankRow($values)) {
                continue;
            }

            // ⚠ Short rows are TOLERATED by padding, unlike the catalogue
            // importer. A spreadsheet routinely drops trailing empty cells, and
            // every column after `name` here is optional - so a 2-column row is
            // a perfectly meaningful supplier, not a broken file. A row with
            // MORE values than the header is still fatal, because that means
            // the columns do not line up.
            if (count($values) > count($header)) {
                throw new RuntimeException(
                    "Line {$line} has ".count($values).' values but the header declares '.count($header).
                    '. Fix the file rather than guessing which column is which.'
                );
            }

            $values = array_pad($values, count($header), '');

            $rows[] = [
                'line' => $line,
                'values' => array_map(
                    fn ($v) => trim((string) $v),
                    array_combine($header, $values)
                ),
            ];
        }

        return $rows;
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

            // ⚠⚠ Excel writes a UTF-8 BOM - and so does our own export, above.
            // Without stripping it the first column reads as "\u{FEFF}code",
            // the mandatory-column check fails, and the message gives no hint
            // why: the two strings look identical in every terminal.
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
        // `phone_number` instead of `phone` imports perfectly and silently
        // drops every phone number, and nothing downstream can ever tell.
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
