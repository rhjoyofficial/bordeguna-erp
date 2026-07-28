<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Models\AttendanceImportBatch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * RPT-01 #6 — Device / import exception.
 *
 * The rows a biometric export brought in that the system could not use, with the
 * file and line they came from.
 *
 * **One row per rejected line, not one per file.** A batch summary — "412 rows,
 * 9 failed" — is a status, and the person reading this report has to go and fix
 * those nine. So the report unpacks `AttendanceImportBatch.report`, which 2b
 * already recorded per row with its line number and reason, and puts each
 * failure on its own line with the enroll id that could not be matched. That
 * enroll id is usually the whole answer: an employee whose `biometric_enroll_id`
 * was never filled in.
 *
 * ⚠ **Duplicates are exceptions here, and they are not failures.** ATT-08's
 * de-duplication means re-importing an overlapping export is normal and
 * harmless — a partially-overlapping file is *supposed* to bring in only its new
 * rows. They appear because "why did 300 of my 412 rows not import" is a real
 * question with a reassuring answer, and they carry their own status so nobody
 * reads them as errors.
 *
 * ⚠ **The stored list is capped at 500 per batch (2b), and the report says so
 * rather than quietly showing fewer rows than the failure count.** The counts on
 * the batch stay exact; the itemised list is what was truncated.
 */
class ImportExceptionReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'attendance_import_exceptions';
    }

    public function name(): string
    {
        return 'Device and import exceptions';
    }

    public function description(): string
    {
        return 'Every row a biometric import could not use, with its file, line number and reason — plus the duplicates it correctly skipped.';
    }

    public function group(): string
    {
        return 'Attendance';
    }

    public function permission(): string
    {
        return 'hrm.attendance.manage';
    }

    public function parameters(): array
    {
        return [
            // Optional: "show me every import problem there has ever been" is
            // the first question anyone asks of this report, and requiring a
            // range would make them guess when the bad file was loaded.
            ReportParameter::dateRange('range', 'Imported between', required: false),
            // ⚠ C10d binds the department scope to this key. This report has no
            // department column of its own — an import is a file, not a person —
            // so the parameter is declared to keep the scope bindable and the
            // rows are filtered by the departments of the employees involved.
            ReportParameter::department(),
            ReportParameter::select('outcome', 'Show', [
                ['value' => 'all', 'label' => 'Failures and duplicates'],
                ['value' => 'failed', 'label' => 'Failures only'],
                ['value' => 'duplicate', 'label' => 'Duplicates only'],
            ], default: 'failed'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::dateTime('imported_at', 'Imported'),
            ReportColumn::text('filename', 'File', 28),
            ReportColumn::text('device_identifier', 'Device', 18),
            ReportColumn::integer('line', 'Line', 8),
            ReportColumn::text('enroll_id', 'Enroll ID', 12),
            ReportColumn::text('employee_name', 'Matched employee', 24),
            ReportColumn::text('outcome', 'Outcome', 12),
            ReportColumn::text('reason', 'Reason', 44),
            ReportColumn::text('uploaded_by', 'Uploaded by', 20),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return [];
    }

    /**
     * Streams the batches and unpacks each one's stored failures inside the
     * loop, so only a single batch's list is ever in memory — the same property
     * the register keeps, reached a different way.
     */
    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $wanted = $parameters->string('outcome') ?? 'failed';
            $employees = $this->employeesByEnroll($parameters);
            $filtering = $parameters->int('department_id') !== null;

            foreach ($this->query($parameters)->cursor() as $batch) {
                foreach ($batch->report ?? [] as $failure) {
                    $status = (string) ($failure['status'] ?? 'failed');

                    if ($wanted !== 'all' && $status !== $wanted) {
                        continue;
                    }

                    $enroll = $this->normalise($failure['enroll_id'] ?? null);
                    $employee = $enroll === null ? null : ($employees[$enroll] ?? null);

                    // A department filter can only be applied to a row whose
                    // employee is known. An unmatched enroll id belongs to
                    // nobody yet — which is precisely the failure being
                    // reported — so it is withheld from a filtered run rather
                    // than attributed to a department it may not be in.
                    if ($filtering && $employee === null) {
                        continue;
                    }

                    yield [
                        'imported_at' => $batch->created_at,
                        'filename' => $batch->filename,
                        'device_identifier' => $batch->device_identifier,
                        'line' => $failure['row'] ?? null,
                        'enroll_id' => $failure['enroll_id'] ?? null,
                        'employee_name' => $employee,
                        'outcome' => $status === 'duplicate' ? 'Duplicate' : 'Failed',
                        'reason' => $failure['reason'] ?? null,
                        'uploaded_by' => $batch->uploaded_by_name,
                    ];
                }
            }
        });
    }

    /**
     * The enroll ids this run may report on, resolved in one query up front
     * rather than per failure row — a bad file can carry hundreds of them.
     *
     * @return array<string, string>
     */
    private function employeesByEnroll(ReportParameters $parameters): array
    {
        $department = $parameters->int('department_id');

        return Employee::query()
            ->whereNotNull('employees.biometric_enroll_id')
            ->when($department !== null, fn (Builder $q) => $q
                ->join('employee_assignments', 'employee_assignments.employee_id', '=', 'employees.id')
                ->where('employee_assignments.is_primary', true)
                ->where('employee_assignments.department_id', $department))
            ->get(['employees.biometric_enroll_id', 'employees.first_name', 'employees.last_name'])
            ->mapWithKeys(fn ($employee) => [
                $this->normalise($employee->biometric_enroll_id) => trim($employee->first_name.' '.$employee->last_name),
            ])
            ->all();
    }

    /**
     * ⚠ The same leading-zero tolerance the parser applies (`007` and `7` are
     * one person). Matching raw strings here would leave every zero-padded
     * enroll id looking unmatched on a report about matching.
     */
    private function normalise(mixed $enroll): ?string
    {
        if ($enroll === null || $enroll === '') {
            return null;
        }

        return ltrim((string) $enroll, '0') ?: '0';
    }

    /**
     * ⚠ An estimate in *failures*, not batches — and it is allowed to be
     * approximate, which is why the stored counts are summed rather than the
     * lists unpacked. Unpacking to count would produce the rows the estimate
     * exists to avoid producing.
     */
    public function estimatedRows(ReportParameters $parameters): int
    {
        $wanted = $parameters->string('outcome') ?? 'failed';

        $query = $this->query($parameters)->toBase();

        return (int) match ($wanted) {
            'failed' => $query->sum('failed_count'),
            'duplicate' => $query->sum('duplicate_count'),
            default => $query->sum('failed_count') + $this->query($parameters)->toBase()->sum('duplicate_count'),
        };
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(
            title: 'Device and import exceptions',
            subtitle: $parameters->from() === null
                ? null
                : sprintf('%s to %s', $parameters->from()?->format('d M Y'), $parameters->to()?->format('d M Y')),
            periodLocked: null,
            lockNote: 'Each import stores at most 500 itemised exceptions; the counts on the import screen remain exact.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        return AttendanceImportBatch::query()
            ->leftJoin('users', 'users.id', '=', 'attendance_import_batches.uploaded_by')
            ->when(
                $parameters->from(),
                fn (Builder $q, $from) => $q->whereDate('attendance_import_batches.created_at', '>=', $from->toDateString()),
            )
            ->when(
                $parameters->to(),
                fn (Builder $q, $to) => $q->whereDate('attendance_import_batches.created_at', '<=', $to->toDateString()),
            )
            // A batch with nothing to report contributes no rows, so it is
            // excluded here rather than iterated and skipped.
            ->where(function (Builder $q) {
                $q->where('attendance_import_batches.failed_count', '>', 0)
                    ->orWhere('attendance_import_batches.duplicate_count', '>', 0);
            })
            ->orderByDesc('attendance_import_batches.created_at')
            ->select([
                'attendance_import_batches.*',
                'users.name as uploaded_by_name',
            ]);
    }
}
