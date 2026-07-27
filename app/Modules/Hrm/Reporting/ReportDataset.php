<?php

namespace App\Modules\Hrm\Reporting;

use Illuminate\Support\LazyCollection;

/**
 * A report, resolved: its columns, its rows, and what it says about itself.
 *
 * ⚠ **`$rows` is a `LazyCollection` and must stay one.** It is the reason the
 * xlsx writer can stream a monthly register of every employee × every day
 * without the memory an in-memory workbook would need — the whole point of
 * Correction C10a. Calling `->all()` or `->toArray()` on it anywhere in the
 * pipeline silently undoes that choice, and nothing will fail until the report
 * is big enough to matter, which is to say: in production, not in tests.
 *
 * ⚠ **A dataset may be iterated once.** Writers consume it as a stream.
 * `$totals` is filled *by* that iteration (see `ReportTotals`), so it is only
 * complete after the rows have been walked — which is exactly when a writer
 * needs it, and never before.
 */
final class ReportDataset
{
    /**
     * @param  array<int, ReportColumn>  $columns
     * @param  LazyCollection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly LazyCollection $rows,
        public readonly ReportMeta $meta,
        public readonly ReportTotals $totals = new ReportTotals,
    ) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_map(fn (ReportColumn $c) => $c->label, $this->columns);
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_map(fn (ReportColumn $c) => $c->key, $this->columns);
    }

    public function hasTotals(): bool
    {
        return $this->totals->enabled();
    }

    /**
     * The totals aligned to the columns, with the first column carrying the
     * word TOTAL so it can never be mistaken for a data row — the same rule
     * `GenericCsvFormat` already applies to its payee list.
     *
     * ⚠ Call only **after** the rows have been iterated; before that the sums
     * are zero, which would be a wrong figure rather than a missing one.
     *
     * @return array<int, mixed>
     */
    public function totalsRow(): array
    {
        $row = [];

        foreach ($this->columns as $index => $column) {
            if ($index === 0) {
                $row[] = $this->totals->label();

                continue;
            }

            $row[] = $this->totals->get($column->key) ?? '';
        }

        return $row;
    }
}
