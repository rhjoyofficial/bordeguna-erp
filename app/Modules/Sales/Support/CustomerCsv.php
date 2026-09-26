<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\Customer;
use RuntimeException;

/**
 * The customer file: what its columns are, how it is read, and how it is written.
 *
 * ⚠⚠ ONE definition of the shape, used by the template, the export AND the
 * import, for the reason the supplier file states: a template the importer then
 * refuses leaves the person holding it with no way to tell which half is wrong.
 *
 * ⚠ Header problems are fatal rather than per-row errors. A file whose columns
 * are not understood produces a page of row errors that all say the same thing
 * badly.
 */
class CustomerCsv
{
    /**
     * ⚠⚠ `code` is MANDATORY HERE and nullable in the domain, and the two are
     * not in conflict. A customer typed on the screen needs no code; a file
     * that will be corrected and imported a second time needs a key, or the
     * second run creates every customer again. Name cannot be that key: Bangla
     * names compare unequal between the composed and decomposed spellings, and
     * phone must not be, because households and shops legitimately share one
     * number (PhoneIdentity: matching warns, it never blocks).
     */
    public const MANDATORY = ['code', 'name'];

    public const OPTIONAL = [
        'type', 'contact_name', 'phone', 'email', 'address',
        'price_group', 'payment_terms', 'credit_limit', 'bin', 'tin',
        'note', 'is_active',
    ];

    /** What each column is for, shown beside the template. */
    public const DESCRIPTIONS = [
        'code' => 'PERMANENT identifier you choose, uppercase. e.g. CUS-0001',
        'name' => 'The customer as you refer to them. Bangla is fine.',
        'type' => 'retail, shop, dealer, distributor or corporate. Blank means retail (a consumer); a shop needs shop prices.',
        'contact_name' => 'Person to speak to. Leave blank when the customer IS a person.',
        'phone' => 'Bangladesh mobile, with the leading zero. e.g. 01712345678',
        'email' => 'Optional. Must be a real address if given.',
        'address' => 'Free text, one line.',
        'price_group' => 'Price group CODE, e.g. RETAIL or DEALER. Blank means they bill at RETAIL.',
        'payment_terms' => 'Free text. e.g. 30 days, Cash on delivery. Blank means nothing agreed.',
        'credit_limit' => 'Amount in BDT, digits only. Blank means no limit recorded.',
        'bin' => 'Business Identification Number (VAT). Optional.',
        'tin' => 'Taxpayer Identification Number. Optional.',
        'note' => 'Anything worth remembering about this customer.',
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
     * ⚠⚠ HEADER ONLY, no example row. A template carrying a sample customer is
     * a template somebody imports unchanged, and then a fictional buyer is in
     * the sales ledger for ever.
     */
    public static function template(): string
    {
        return self::toCsv([]);
    }

    /**
     * @param  iterable<Customer>  $customers
     */
    public static function export(iterable $customers): string
    {
        $rows = [];

        foreach ($customers as $customer) {
            $row = [];

            foreach (self::columns() as $column) {
                $row[$column] = match ($column) {
                    // ⚠ Written back as the words the importer accepts, never
                    // as 1/0 or an id. An export that cannot be re-imported is
                    // not an export, and this file is meant to round-trip.
                    'is_active' => $customer->is_active ? 'active' : 'inactive',
                    'type' => $customer->type?->value ?? '',
                    'price_group' => $customer->priceGroup?->code ?? '',
                    'credit_limit' => $customer->credit_limit === null ? '' : (string) (float) $customer->credit_limit,
                    default => (string) ($customer->{$column} ?? ''),
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
        // system codepage and renders every Bangla customer name as mojibake,
        // and the person then "fixes" it by retyping.
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
            throw new RuntimeException("Cannot read customer file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open customer file: {$path}");
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
            throw new RuntimeException('The customer file is empty.');
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

            // ⚠ Short rows are padded: a spreadsheet routinely drops trailing
            // empty cells, and every column after `name` is optional, so a
            // two-column row is a perfectly meaningful customer. A row with
            // MORE values than the header stays fatal, because then the
            // columns do not line up and every value is in the wrong field.
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

            // ⚠⚠ Excel writes a UTF-8 BOM, and so does our own export above.
            // Unstripped, the first column reads as "\u{FEFF}code" and the
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
        // `mobile` instead of `phone` imports perfectly and silently drops
        // every phone number, and nothing downstream can ever tell.
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
