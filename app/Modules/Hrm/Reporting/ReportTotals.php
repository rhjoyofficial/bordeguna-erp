<?php

namespace App\Modules\Hrm\Reporting;

/**
 * Column totals, accumulated **during** the single pass over the rows.
 *
 * This exists because of a genuine tension in the design: the dataset streams
 * (Correction C10a), so the rows can be walked exactly once — but a totals row
 * is written *after* the last data row, by which time the stream is spent.
 *
 * The two obvious answers are both wrong. Buffering the rows to total them
 * undoes the streaming outright. Running a second aggregate query re-asks the
 * database a question it has already answered, and — worse — asks it
 * *separately*, so a total can disagree with the rows above it if anything
 * changed in between. A report whose total does not match its own body is the
 * one defect nobody forgives.
 *
 * So this is a mutable accumulator the runner feeds as each row goes past. By
 * the time a writer reaches the footer, it holds the sum of exactly the rows
 * that writer just emitted — the same rows, by construction, not by coincidence.
 */
final class ReportTotals
{
    /** @var array<string, float> */
    private array $sums = [];

    private int $rows = 0;

    /**
     * @param  array<int, string>  $columns  column keys to total
     */
    public function __construct(private readonly array $columns = [])
    {
        foreach ($this->columns as $key) {
            $this->sums[$key] = 0.0;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function add(array $row): void
    {
        $this->rows++;

        foreach ($this->columns as $key) {
            $value = $row[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $this->sums[$key] += (float) $value;
        }
    }

    public function get(string $key): ?float
    {
        return $this->sums[$key] ?? null;
    }

    public function enabled(): bool
    {
        return $this->columns !== [];
    }

    public function rowCount(): int
    {
        return $this->rows;
    }

    /**
     * The label that sits in the first column of the totals row. It states the
     * row count as well as the word TOTAL, because "TOTAL" beside a figure does
     * not say how many things were added to reach it.
     */
    public function label(): string
    {
        return sprintf('TOTAL (%s %s)', number_format($this->rows), $this->rows === 1 ? 'row' : 'rows');
    }
}
