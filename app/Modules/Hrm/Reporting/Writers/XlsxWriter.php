<?php

namespace App\Modules\Hrm\Reporting\Writers;

use App\Modules\Hrm\Reporting\Contracts\ReportWriter;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Modules\Hrm\Reporting\ReportDataset;
use App\Modules\Hrm\Reporting\ReportValue;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The report as a real .xlsx, written with openspout (Correction C10a).
 *
 * **Why this writer streams.** openspout appends each row to the file as it is
 * given, so memory stays flat no matter how many rows arrive. That is the
 * whole reason C10a chose it over PhpSpreadsheet: the monthly attendance
 * register is every employee × every day of the month, and it is the one
 * report guaranteed to grow with the company.
 *
 * **The header is row 1 and nothing precedes it.** No title band, no filter
 * summary, no provenance block. A spreadsheet whose header is on row 4 cannot
 * be sorted, filtered or pivoted without a human first deleting three rows —
 * and the whole reason to ask for Excel rather than PDF is to do those things.
 * The provenance still travels with the file: it goes into the **document
 * properties** (File → Info), where it cannot displace a single cell.
 *
 * ⚠ **Formula injection is handled here, deliberately.** `Cell::fromValue()`
 * turns any string beginning with `=` into a live formula. Report rows carry
 * employee names, correction reasons and adjustment notes — all free text, all
 * typed by users — so a text column is always written as an explicit
 * `StringCell`, never through the auto-detecting factory. A report that
 * executes its own data is a report that can be made to do something else.
 */
class XlsxWriter implements ReportWriter
{
    public function key(): string
    {
        return 'xlsx';
    }

    public function label(): string
    {
        return 'Excel (.xlsx)';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function isDownload(): bool
    {
        return true;
    }

    public function write(ReportDataset $dataset): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report_').'.xlsx';

        $writer = new Writer($this->options($dataset));
        $writer->openToFile($path);

        $headerStyle = $this->headerStyle();

        $writer->addRow(new Row(array_map(
            fn (string $heading) => new StringCell($heading, $headerStyle),
            $dataset->headings(),
        )));

        $styles = $this->columnStyles($dataset->columns);

        foreach ($dataset->rows as $row) {
            $writer->addRow(new Row($this->cells($dataset->columns, $styles, $row)));
        }

        if ($dataset->hasTotals()) {
            // Through the same per-column formatter as the body, so a totals
            // cell cannot render differently from the column it totals.
            $writer->addRow(new Row($this->totalCells($dataset)));
        }

        $writer->close();

        return $path;
    }

    private function options(ReportDataset $dataset): Options
    {
        $options = new Options(properties: new Properties(
            title: $dataset->meta->title,
            subject: $dataset->meta->subtitle,
            application: 'Bor de Guna ERP',
            creator: $dataset->meta->generatedBy ?? 'Bor de Guna ERP',
            lastModifiedBy: $dataset->meta->generatedBy ?? 'Bor de Guna ERP',
            // The stamp that would otherwise sit above the header row. It is
            // still on the file, just not in the way of it.
            description: $dataset->meta->stamp(),
            customProperties: $dataset->meta->filters,
        ));

        // openspout numbers columns from 1. A declared width is a preference,
        // not a layout — a column that is too narrow shows ####, which reads
        // as broken rather than as narrow.
        foreach ($dataset->columns as $index => $column) {
            if ($column->width !== null) {
                $options->setColumnWidth((float) $column->width, $index + 1);
            }
        }

        return $options;
    }

    /**
     * @param  array<int, ReportColumn>  $columns
     * @param  array<int, Style|null>  $styles
     * @param  array<string, mixed>  $row
     * @return array<int, Cell>
     */
    private function cells(array $columns, array $styles, array $row): array
    {
        $cells = [];

        foreach ($columns as $index => $column) {
            $value = $row[$column->key] ?? null;
            $style = $styles[$index];

            if ($column->type->isNumeric()) {
                // A real number, so Excel sorts and SUMs it — the single most
                // common failing of a generated spreadsheet is a column of
                // amounts stored as text.
                $cells[] = Cell::fromValue(ReportValue::forSpreadsheet($value, $column->type), $style);

                continue;
            }

            $cells[] = new StringCell(ReportValue::forCsv($value, $column->type), $style);
        }

        return $cells;
    }

    /**
     * @return array<int, Cell>
     */
    private function totalCells(ReportDataset $dataset): array
    {
        $bold = (new Style)->withFontBold(true);
        $cells = [];

        foreach ($dataset->columns as $index => $column) {
            if ($index === 0) {
                $cells[] = new StringCell($dataset->totals->label(), $bold);

                continue;
            }

            $value = $dataset->totals->get($column->key);

            if ($value === null) {
                $cells[] = new StringCell('', $bold);

                continue;
            }

            $cells[] = $column->type->isNumeric()
                ? Cell::fromValue(ReportValue::forSpreadsheet($value, $column->type), $bold->withFormat('#,##0.00'))
                : new StringCell(ReportValue::forCsv($value, $column->type), $bold);
        }

        return $cells;
    }

    /**
     * One style per column, built once rather than per cell — a style object
     * allocated for every cell of a 30,000-row register is the other half of
     * the memory problem this writer exists to avoid.
     *
     * @param  array<int, ReportColumn>  $columns
     * @return array<int, Style|null>
     */
    private function columnStyles(array $columns): array
    {
        return array_map(function (ReportColumn $column) {
            return match ($column->type) {
                ReportColumnType::Money, ReportColumnType::Decimal => (new Style)
                    ->withFormat('#,##0.00')
                    ->withCellAlignment(CellAlignment::RIGHT),
                ReportColumnType::Integer => (new Style)
                    ->withFormat('#,##0')
                    ->withCellAlignment(CellAlignment::RIGHT),
                ReportColumnType::Date, ReportColumnType::DateTime,
                ReportColumnType::Time, ReportColumnType::Boolean => (new Style)
                    ->withCellAlignment(CellAlignment::CENTER),
                default => null,
            };
        }, $columns);
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->withFontBold(true)
            ->withBackgroundColor('EEF2F7');
    }
}
