<?php

namespace App\Modules\Hrm\Support;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Turns an uploaded attendance export into rows, whatever shape it arrived in.
 *
 * ZKTime.Net will export the same `AllInOutData` report as a legacy `.xls`, an
 * `.xlsx` or a CSV depending on which button HR presses, and the device Bor De
 * Guna owns defaults to the legacy binary one. Requiring a hand conversion to
 * CSV before every upload was the previous state and put a manual step in front
 * of the one job that has to happen every week.
 *
 * This class only reads. It hands `AttendanceCsvParser` exactly the shape that
 * parser already understands — a header line plus data lines — so all the
 * column-alias and date-format knowledge stays in one place rather than being
 * duplicated per file type.
 *
 * ⚠ **Dates from a spreadsheet are emitted as `Y-m-d H:i:s`, deliberately.**
 * A date cell holds a number, not text, so the US-vs-day-first ambiguity that
 * dominates the CSV path does not exist here — and re-rendering it as the
 * device's own `M/d/Y h:mm A` would manufacture that ambiguity for no reason.
 * `Y-m-d H:i:s` is the first format the parser tries and can only be read one
 * way.
 */
class AttendanceFileReader
{
    /** Extensions handled by the spreadsheet reader rather than as plain text. */
    private const SPREADSHEET_EXTENSIONS = ['xls', 'xlsx', 'xlsm', 'ods'];

    /**
     * @return array<int, array<int, string>>
     *
     * @throws ValidationException
     */
    public function read(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SPREADSHEET_EXTENSIONS, true)) {
            return $this->readDelimited($path);
        }

        return $this->readSpreadsheet($path, $filename);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readDelimited(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }

        return (new AttendanceCsvParser)->readLines($contents);
    }

    /**
     * @return array<int, array<int, string>>
     *
     * @throws ValidationException
     */
    private function readSpreadsheet(string $path, string $filename): array
    {
        try {
            $type = IOFactory::identify($path);
            $reader = IOFactory::createReader($type);
            // Formatting is irrelevant to us, but the flag must stay FALSE:
            // read-data-only skips the cell styles that carry the date format,
            // and without those a punch timestamp is just an unrecognisable
            // number like 46228.34.
            $reader->setReadDataOnly(false);
            $sheet = $reader->load($path)->getActiveSheet();
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'file' => "{$filename} could not be read as a spreadsheet: {$e->getMessage()}",
            ]);
        }

        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $cells = [];

            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                $cells[] = $this->value($cell);
            }

            // ⚠ A legacy .xls routinely reports its width as the sheet maximum
            // (IW, 256 columns) rather than the eight that hold anything, so
            // every row arrives with ~250 empty trailing cells. Left in, the
            // header row's "columns found" message becomes unreadable and every
            // row carries pointless bulk.
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => "{$filename} has no rows on its first sheet.",
            ]);
        }

        return $rows;
    }

    /**
     * A cell as text the parser can read.
     *
     * ⚠ **`getCalculatedValue()`, not `getValue()`.** Bor de Guna's own export
     * builds `CardNo` with the formula `="bdg-"&F2`, so the raw value is the
     * formula source rather than `bdg-000020`. Nothing keys on CardNo today —
     * the enroll column is `No.` — but a reader that hands formulas downstream
     * is a trap waiting for the first person who does use it.
     */
    private function value(Cell $cell): string
    {
        try {
            if (ExcelDate::isDateTime($cell) && is_numeric($cell->getValue())) {
                return ExcelDate::excelToDateTimeObject((float) $cell->getValue())
                    ->format('Y-m-d H:i:s');
            }

            $value = $cell->getCalculatedValue();
        } catch (Throwable) {
            // A formula that cannot be evaluated must not lose the whole upload;
            // the row still carries its enroll id and timestamp elsewhere.
            $value = $cell->getOldCalculatedValue() ?? $cell->getValue();
        }

        if ($value === null || is_array($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
