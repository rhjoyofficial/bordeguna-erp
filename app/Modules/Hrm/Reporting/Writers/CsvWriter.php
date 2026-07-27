<?php

namespace App\Modules\Hrm\Reporting\Writers;

use App\Modules\Hrm\Reporting\Contracts\ReportWriter;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportDataset;
use App\Modules\Hrm\Reporting\ReportValue;

/**
 * The report as a plain CSV.
 *
 * Data only — no title block, no filter summary, no provenance stamp above the
 * header row. Those belong on a document a human reads; in a CSV they push the
 * real header down a line and break every importer that assumes row 1 is the
 * header. The stamp still travels with the file, in the filename.
 *
 * Written straight to a temp file rather than assembled in memory, for the same
 * reason the xlsx writer streams (Correction C10a).
 */
class CsvWriter implements ReportWriter
{
    public function key(): string
    {
        return 'csv';
    }

    public function label(): string
    {
        return 'CSV';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function isDownload(): bool
    {
        return true;
    }

    public function write(ReportDataset $dataset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report_');
        $handle = fopen($path, 'w');

        // A BOM, so Excel on Windows opens a UTF-8 CSV as UTF-8 rather than as
        // Latin-1. Without it every Bangla name in a report arrives as mojibake
        // — and this file will hold Bangla names.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $dataset->headings());

        foreach ($dataset->rows as $row) {
            fputcsv($handle, $this->line($dataset->columns, $row));
        }

        if ($dataset->hasTotals()) {
            // ⚠ The totals go through the SAME formatter as the body. Writing
            // them raw put "530" under a column whose rows read "8h 50m" —
            // caught in live verification, and exactly the drift this
            // framework exists to prevent, appearing inside a single column.
            fputcsv($handle, $this->totalsLine($dataset));
        }

        fclose($handle);

        return $path;
    }

    /**
     * @param  array<int, ReportColumn>  $columns
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function line(array $columns, array $row): array
    {
        return array_map(
            fn (ReportColumn $column) => ReportValue::forCsv($row[$column->key] ?? null, $column->type),
            $columns,
        );
    }

    /**
     * @return array<int, string>
     */
    private function totalsLine(ReportDataset $dataset): array
    {
        $line = [];

        foreach ($dataset->columns as $index => $column) {
            $line[] = $index === 0
                ? $dataset->totals->label()
                : ReportValue::forCsv($dataset->totals->get($column->key), $column->type);
        }

        return $line;
    }
}
