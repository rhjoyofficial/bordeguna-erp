<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendanceImportBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\AttendanceCsvParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Imports a biometric attendance export (ATT-01/02/08).
 *
 * Idempotent by construction: every row goes through RecordPunch, whose dedup
 * key is device + employee + timestamp, so re-running the same export imports
 * nothing new. Duplicates are **reported, never silently ignored and never
 * allowed to block the file** — the rest of the rows still import, which is
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
        private readonly RecordPunch $recordPunch,
    ) {}

    public function handle(
        string $contents,
        string $filename,
        PunchSource $source = PunchSource::Biometric,
        ?string $deviceIdentifier = null,
    ): AttendanceImportBatch {
        $parsed = $this->parser->parse($contents);

        // Resolved once rather than per row: an export is thousands of lines
        // over a handful of people.
        $employeesByEnroll = Employee::whereNotNull('biometric_enroll_id')
            ->get()
            ->keyBy(fn (Employee $employee) => $this->normaliseEnroll($employee->biometric_enroll_id));

        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        $report = [];

        DB::transaction(function () use (
            $parsed, $source, $deviceIdentifier, $employeesByEnroll,
            &$imported, &$duplicates, &$failed, &$report
        ) {
            foreach ($parsed['rows'] as $row) {
                $outcome = $this->importRow($row, $source, $deviceIdentifier, $employeesByEnroll);

                match ($outcome['status']) {
                    'imported' => $imported++,
                    'duplicate' => $duplicates++,
                    default => $failed++,
                };

                if ($outcome['status'] !== 'imported') {
                    $report[] = [
                        'row' => $row['row'],
                        'status' => $outcome['status'],
                        'reason' => $outcome['reason'],
                        'enroll_id' => $row['enroll_id'],
                    ];
                }
            }
        });

        return AttendanceImportBatch::create([
            'filename' => $filename,
            'source' => $source,
            'device_identifier' => $deviceIdentifier,
            'total_rows' => count($parsed['rows']),
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'failed_count' => $failed,
            // Capped so one catastrophically wrong file cannot bloat the row;
            // the counts above stay exact regardless.
            'report' => array_slice($report, 0, 500),
            'uploaded_by' => Auth::id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  \Illuminate\Support\Collection<string, Employee>  $employeesByEnroll
     * @return array{status: string, reason: string|null}
     */
    private function importRow(array $row, PunchSource $source, ?string $deviceIdentifier, $employeesByEnroll): array
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
