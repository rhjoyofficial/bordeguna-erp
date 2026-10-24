<?php

namespace App\Support\Lifetime;

use App\Modules\Foundation\Support\CsvSafe;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ B23 (25 Oct 2026): the "Excel" button of a lifetime list. A UTF-8 CSV
 * with the byte order mark, so Excel on Windows shows a Bangla name as Bangla
 * (the day book's lesson), and every text cell through CsvSafe so a name
 * starting with "=" stays a name.
 *
 * ⚠ A plain number (an amount, a quantity, "-1,250.00") is written as it is:
 * CsvSafe would put a quote before a minus sign and Excel would read it as
 * text that cannot be summed.
 */
final class LifetimeCsv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function download(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => self::cell($cell), array_values($row)));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;

        return is_numeric(str_replace(',', '', $text)) ? str_replace(',', '', $text) : CsvSafe::cell($text);
    }
}
