<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ManualPayrollLine;
use App\Modules\Hrm\Models\PayrollPeriod;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports Bor De Guna's own payroll sheet for a period (Correction C16a).
 *
 * The expected shape is **wide**: one row per employee, one column per item, as
 * a payroll spreadsheet is actually kept. Headings are matched by alias rather
 * than by a fixed layout, following `AttendanceCsvParser`'s discipline — the
 * real ZKTeco export taught this project that a client's actual column names
 * are never the ones the design assumed.
 *
 * ⚠ **Unrecognised columns are imported, not dropped.** They arrive with a
 * normalised key and appear on the reconciliation as unmatched. A silently
 * ignored column is a figure nobody compared, which is the one outcome a
 * parallel run cannot afford.
 *
 * ⚠ **Employees the system does not know are imported too**, with a null
 * `employee_id` and their sheet reference kept verbatim. Somebody on the manual
 * payroll and not in the system is exactly what this exercise exists to find.
 *
 * ⚠ **Re-runnable.** Keyed on the unique index, so re-importing a corrected
 * sheet updates rather than duplicating — which is what makes fixing a typo in
 * the source safe rather than a cleanup job.
 */
class ImportManualPayroll
{
    /** Headings that identify the employee rather than carry a figure. */
    private const REF_ALIASES = [
        'employeecode', 'code', 'empcode', 'employeeid', 'empid', 'id', 'employee', 'name', 'employeename',
    ];

    /**
     * @return array{imported: int, updated: int, unmatched_employees: array<int, string>, unknown_items: array<int, string>, failures: array<int, string>}
     */
    public function handle(PayrollPeriod $period, string $contents, ?string $filename = null): array
    {
        $rows = $this->readCsv($contents);

        if ($rows === []) {
            throw new RuntimeException('The file contains no rows.');
        }

        $headers = array_shift($rows);
        $refIndex = $this->findReferenceColumn($headers);

        if ($refIndex === null) {
            throw new RuntimeException(sprintf(
                'No employee column found. Expected one of: %s. Found: %s.',
                implode(', ', self::REF_ALIASES),
                implode(', ', array_map(fn ($h) => trim((string) $h), $headers)),
            ));
        }

        $imported = 0;
        $updated = 0;
        $unmatched = [];
        $unknownItems = [];
        $failures = [];

        // Resolved once, not per row: nine employees is small, but this is the
        // same shape as the report and should not fire a query per figure.
        $employees = Employee::query()->get(['id', 'employee_code', 'first_name', 'last_name'])
            ->keyBy(fn (Employee $e) => $this->normalise($e->employee_code));

        foreach ($rows as $number => $line) {
            $lineNumber = $number + 2; // header is line 1

            $ref = trim((string) ($line[$refIndex] ?? ''));

            if ($ref === '') {
                continue; // a blank spacer row, not a failure
            }

            $employee = $employees->get($this->normalise($ref));

            if ($employee === null) {
                $unmatched[] = $ref;
            }

            foreach ($headers as $column => $heading) {
                if ($column === $refIndex) {
                    continue;
                }

                $label = trim((string) $heading);
                $raw = trim((string) ($line[$column] ?? ''));

                if ($label === '' || $raw === '') {
                    continue;
                }

                $amount = $this->parseAmount($raw);

                if ($amount === null) {
                    $failures[] = sprintf('Line %d, "%s": "%s" is not a number.', $lineNumber, $label, $raw);

                    continue;
                }

                $key = ManualPayrollLine::keyFor($label);

                if (! array_key_exists($key, ManualPayrollLine::ITEM_ALIASES)) {
                    $unknownItems[] = $label;
                }

                $existing = ManualPayrollLine::query()
                    ->forPeriod($period->id)
                    ->where('source_employee_ref', $ref)
                    ->where('item_key', $key)
                    ->first();

                if ($existing !== null) {
                    $existing->update([
                        'employee_id' => $employee?->id,
                        'item_label' => $label,
                        'amount' => $amount,
                        'source_row' => $lineNumber,
                        'source_file' => $filename,
                    ]);
                    $updated++;

                    continue;
                }

                ManualPayrollLine::create([
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee?->id,
                    'source_employee_ref' => $ref,
                    'item_label' => $label,
                    'item_key' => $key,
                    'amount' => $amount,
                    'source_row' => $lineNumber,
                    'source_file' => $filename,
                ]);
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'unmatched_employees' => array_values(array_unique($unmatched)),
            'unknown_items' => array_values(array_unique($unknownItems)),
            'failures' => $failures,
        ];
    }

    private function findReferenceColumn(array $headers): ?int
    {
        foreach ($headers as $index => $heading) {
            if (in_array($this->normalise((string) $heading), self::REF_ALIASES, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * ⚠ Strips thousands separators and currency marks, and treats a
     * parenthesised figure as negative — accountants write (500) for -500 and a
     * payroll sheet maintained by hand will contain both conventions.
     */
    private function parseAmount(string $raw): ?float
    {
        $negative = str_starts_with($raw, '(') && str_ends_with($raw, ')');
        $cleaned = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        $value = (float) $cleaned;

        return $negative ? -abs($value) : $value;
    }

    private function normalise(?string $value): string
    {
        return Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]/', '')->value();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $delimiter = substr_count($contents, ';') > substr_count($contents, ',') ? ';' : ',';

        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($contents)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = str_getcsv($line, $delimiter, '"', '\\');
        }

        return $rows;
    }
}
