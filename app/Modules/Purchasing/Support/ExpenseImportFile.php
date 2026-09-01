<?php

namespace App\Modules\Purchasing\Support;

use RuntimeException;

/**
 * The expense file, read.
 *
 * ⚠⚠ ONE ROW IS ONE VOUCHER, and this is the decision the whole importer rests
 * on. Grouping rows into multi-line vouchers by a shared reference was the
 * obvious alternative and is rejected: two people paying two different bills
 * who happen to type the same reference would have their payments silently
 * merged into one document, and nothing on the preview would look wrong. A
 * split voucher stays a deliberate act on the screen, where the person doing
 * the splitting can see both halves.
 *
 * ⚠ Columns are matched by NAME, not position, so a spreadsheet with the
 * columns in a different order still imports. Anything the reader does not
 * recognise is reported back rather than ignored in silence - a `amont` column
 * with every amount in it must not import as a file of zero-value vouchers.
 */
class ExpenseImportFile
{
    /** @var list<string> */
    public const COLUMNS = [
        'date',
        'payee',
        'account_code',
        'description',
        'amount',
        'paid_from',
        'reference',
        'note',
    ];

    /** Without these there is no voucher to write. */
    private const REQUIRED = ['date', 'account_code', 'amount', 'paid_from'];

    /**
     * @return array{rows: list<array{line:int, values:array<string,string>}>, ignored: list<string>}
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read the expense file: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the expense file: {$path}");
        }

        try {
            return self::readHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return array{rows: list<array{line:int, values:array<string,string>}>, ignored: list<string>}
     */
    public static function readHandle($handle): array
    {
        $header = fgetcsv($handle);

        if ($header === false || $header === [null]) {
            throw new RuntimeException('That file is empty.');
        }

        $header = self::normaliseHeader($header);

        $missing = array_values(array_diff(self::REQUIRED, $header));

        if ($missing !== []) {
            throw new RuntimeException(
                'The file is missing the column(s): '.implode(', ', $missing)
                .'. The header must name its columns; download the template if in doubt.'
            );
        }

        $ignored = array_values(array_diff(array_filter($header), self::COLUMNS));

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if ($values === [null] || self::isBlank($values)) {
                continue;
            }

            // ⚠ MORE values than the header means the columns do not line up,
            // and guessing which is which is how an amount becomes a date.
            if (count($values) > count($header)) {
                throw new RuntimeException(
                    "Line {$line} has ".count($values).' values but the header declares '.count($header)
                    .'. Fix the file rather than guessing which column is which.'
                );
            }

            $values = array_pad($values, count($header), '');

            $rows[] = [
                'line' => $line,
                'values' => array_map(fn ($v) => trim((string) $v), array_combine($header, $values)),
            ];
        }

        return ['rows' => $rows, 'ignored' => $ignored];
    }

    /**
     * The file this importer reads back, with one example row.
     *
     * ⚠ Handed out rather than described in help text, because a template that
     * came from the same constant as the reader cannot drift from it.
     */
    public static function template(): string
    {
        $out = fopen('php://temp', 'r+');

        // ⚠⚠ UTF-8 BOM, or Excel on Windows mangles every Bangla name.
        fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, self::COLUMNS);
        fputcsv($out, [
            '2026-09-30',
            'Nikola Host',
            '6225',
            'Shared hosting, September 2026',
            '4900.00',
            'BRAC Bank',
            'NIKOLA-2026-09',
            'Paid by card',
        ]);

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private static function normaliseHeader(array $header): array
    {
        return array_map(function ($name, $index) {
            $clean = strtolower(trim((string) $name));

            // The BOM Excel writes rides on the first cell and would otherwise
            // make `date` an unrecognised column on every file it produces.
            if ($index === 0) {
                $clean = preg_replace('/^\x{FEFF}/u', '', $clean) ?? $clean;
            }

            return preg_replace('/[^a-z0-9]+/', '_', $clean) ?? $clean;
        }, $header, array_keys($header));
    }

    /**
     * @param  list<string|null>  $values
     */
    private static function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
