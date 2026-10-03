<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendanceImportBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\AttendanceCsvParser;
use App\Modules\Hrm\Support\AttendanceFileReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports a biometric attendance export (ATT-01/02/08).
 *
 * Idempotent by construction: every row goes through RecordPunch, whose dedup
 * key is device + employee + timestamp, so re-running the same export imports
 * nothing new. Duplicates are **reported, never silently ignored and never
 * allowed to block the file** - the rest of the rows still import, which is
 * what ATT-08 asks for and what makes a partially-overlapping export (the
 * normal case when HR re-pulls a date range) usable.
 *
 * A row that cannot be resolved is recorded with its line number and reason
 * rather than dropped, so "12 failed" is always explainable.
 */
class ImportAttendancePunches
{
    public function __construct(
        private readonly AttendanceCsvParser $parser,
        private readonly AttendanceFileReader $reader,
        private readonly RecordPunch $recordPunch,
        private readonly ProcessAttendance $processAttendance,
    ) {}

    /**
     * Import from a file on disk, in whatever format it was exported.
     *
     * This is the path an upload takes. `.xls`, `.xlsx`, `.ods`, CSV and plain
     * text all arrive here and converge on the same parser, so HR never has to
     * convert the device's export by hand before uploading it.
     */
    public function handleFile(
        string $path,
        string $filename,
        PunchSource $source = PunchSource::Biometric,
        ?string $deviceIdentifier = null,
    ): AttendanceImportBatch {
        return $this->import(
            $this->parser->parseLines($this->reader->read($path, $filename)),
            $filename,
            $source,
            $deviceIdentifier,
        );
    }

    public function handle(
        string $contents,
        string $filename,
        PunchSource $source = PunchSource::Biometric,
        ?string $deviceIdentifier = null,
    ): AttendanceImportBatch {
        return $this->import($this->parser->parse($contents), $filename, $source, $deviceIdentifier);
    }

    /**
     * @param  array{rows: array<int, array<string, mixed>>, headers: array<int, string>}  $parsed
     */
    private function import(
        array $parsed,
        string $filename,
        PunchSource $source,
        ?string $deviceIdentifier,
    ): AttendanceImportBatch {

        // Resolved once rather than per row: an export is thousands of lines
        // over a handful of people.
        $employeesByEnroll = Employee::whereNotNull('biometric_enroll_id')
            ->get()
            ->keyBy(fn (Employee $employee) => $this->normaliseEnroll($employee->biometric_enroll_id));

        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        $report = [];
        $importedDates = [];

        // ⚠ Found 3 Oct 2026: the batch used to be created AFTER the rows, so no
        // punch ever carried import_batch_id and "which file brought this punch
        // in" had no answer. The batch now exists first; its counts are filled
        // in once the rows are done.
        $batch = DB::transaction(function () use (
            $parsed, $filename, $source, $deviceIdentifier, $employeesByEnroll,
            &$imported, &$duplicates, &$failed, &$report, &$importedDates
        ) {
            $batch = AttendanceImportBatch::create([
                'filename' => $filename,
                'source' => $source,
                'device_identifier' => $deviceIdentifier,
                'total_rows' => count($parsed['rows']),
                'imported_count' => 0,
                'duplicate_count' => 0,
                'failed_count' => 0,
                'report' => [],
                'uploaded_by' => Auth::id(),
            ]);

            foreach ($parsed['rows'] as $row) {
                $outcome = $this->importRow($row, $source, $deviceIdentifier, $employeesByEnroll, $batch->id);

                match ($outcome['status']) {
                    'imported' => $imported++,
                    'duplicate' => $duplicates++,
                    default => $failed++,
                };

                if ($outcome['status'] === 'imported') {
                    // The date in the APP timezone, not the instant's own: a
                    // parser path that yields a UTC instant would otherwise
                    // file an early-morning punch under the previous day.
                    $importedDates[$row['punched_at']->copy()->timezone(config('app.timezone'))->toDateString()] = true;
                } else {
                    $report[] = [
                        'row' => $row['row'],
                        'status' => $outcome['status'],
                        'reason' => $outcome['reason'],
                        'enroll_id' => $row['enroll_id'],
                    ];
                }
            }

            $batch->update([
                'imported_count' => $imported,
                'duplicate_count' => $duplicates,
                'failed_count' => $failed,
                // Capped so one catastrophically wrong file cannot bloat the row;
                // the counts above stay exact regardless.
                'report' => array_slice($report, 0, 500),
            ]);

            return $batch;
        });

        // ⚠⚠ The lesson this closes: an import lands PUNCHES, and every screen
        // kept showing the old world until somebody ran the processing command
        // or the 23:00 scheduler did - "imported but showing nothing", three
        // times in one week. The engine now runs here, for exactly the dates
        // this batch touched, the moment the punches are committed.
        $this->recomputeImportedDays($batch, array_keys($importedDates));

        return $batch;
    }

    /**
     * Recompute the attendance days this batch brought new evidence for.
     *
     * Force on purpose: a new punch on an already-judged (even finalised) day
     * IS new evidence for that day - the same semantics an approved correction
     * already carries. Dates the batch did not touch are left alone, and days
     * are processed in calendar order so the occurrence ladders count up
     * exactly as they would have day by day.
     *
     * Runs AFTER the punch transaction committed, and a failure here never
     * takes the import down with it: the punches are already safe, the error
     * is reported, and the nightly run remains the sweep-up.
     *
     * @param  array<int, string>  $dates
     */
    private function recomputeImportedDays(AttendanceImportBatch $batch, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        sort($dates);

        // Measured with the realistic case (300 rows over 31 days, 9
        // employees): ~2s in the suite, well inside any request budget - a
        // plain ordered loop beats queueing machinery here. A multi-month
        // device dump just takes proportionally longer; nothing times out at
        // this data size, and the nightly run would cover a genuine abort.
        $recomputed = 0;

        foreach ($dates as $date) {
            try {
                $this->processAttendance->forDate($date, force: true);
                $recomputed++;
            } catch (Throwable $e) {
                $batch->processingError = sprintf(
                    'Automatic recompute stopped at %s: %s The nightly run will finish the remaining days.',
                    $date,
                    rtrim($e->getMessage(), '.').'.',
                );

                break;
            }
        }

        $batch->recomputedDays = $recomputed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Employee>  $employeesByEnroll
     * @return array{status: string, reason: string|null}
     */
    private function importRow(array $row, PunchSource $source, ?string $deviceIdentifier, $employeesByEnroll, int $batchId): array
    {
        if ($row['enroll_id'] === null) {
            return ['status' => 'failed', 'reason' => 'No employee/enroll ID on this row.'];
        }

        if ($row['punched_at'] === null) {
            return ['status' => 'failed', 'reason' => 'Could not read a valid date/time on this row.'];
        }

        $employee = $employeesByEnroll->get($this->normaliseEnroll($row['enroll_id']));

        if ($employee === null) {
            return [
                'status' => 'failed',
                'reason' => "No employee is mapped to enroll ID {$row['enroll_id']}.",
            ];
        }

        $punch = $this->recordPunch->handle($employee, $row['punched_at'], $source, [
            'direction' => $row['direction'],
            // A device column in the file beats the one chosen at upload time.
            'device_identifier' => $row['device_identifier'] ?: $deviceIdentifier,
            'enroll_id' => $row['enroll_id'],
            'import_batch_id' => $batchId,
        ]);

        return $punch === null
            ? ['status' => 'duplicate', 'reason' => 'Already imported (same employee, time and device).']
            : ['status' => 'imported', 'reason' => null];
    }

    /**
     * Enroll IDs drift between the device and HR's spreadsheet ("007" vs "7"),
     * so match on a normalised form rather than losing rows to formatting.
     */
    private function normaliseEnroll(string $value): string
    {
        $trimmed = ltrim(trim($value), '0');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
