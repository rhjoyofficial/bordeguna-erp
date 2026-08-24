<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\ImportAttendancePunches;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendanceImportBatch;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Biometric / CSV attendance import (ATT-01/02/08).
 *
 * The screen leads with the import history and its per-row report, because the
 * thing HR actually needs after uploading is "what happened to my 2,000 rows",
 * not a bare success toast.
 */
class AttendanceImportController extends Controller
{
    public function index(): Response
    {
        $this->authorize('hrm.attendance.manage');

        return Inertia::render('hrm/attendance/import', [
            'batches' => AttendanceImportBatch::with('uploader:id,name')
                ->orderByDesc('id')->limit(25)->get()
                ->map(fn (AttendanceImportBatch $batch) => [
                    'id' => $batch->id,
                    'filename' => $batch->filename,
                    'source' => $batch->source->label(),
                    'device_identifier' => $batch->device_identifier,
                    'total_rows' => $batch->total_rows,
                    'imported_count' => $batch->imported_count,
                    'duplicate_count' => $batch->duplicate_count,
                    'failed_count' => $batch->failed_count,
                    'entirely_duplicate' => $batch->wasEntirelyDuplicate(),
                    'report' => $batch->report ?? [],
                    'uploaded_by' => $batch->uploader?->name,
                    'uploaded_at' => $batch->created_at?->format('Y-m-d H:i'),
                ]),
            'sources' => collect([PunchSource::Biometric, PunchSource::CsvImport])
                ->map(fn (PunchSource $source) => ['value' => $source->value, 'label' => $source->label()]),
            // Import matches on the device's enroll ID, so an employee without
            // one is invisible to it. Surfaced up front rather than discovered
            // as a wall of "no employee mapped" failures.
            'unmapped_employees' => Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
                ->whereNull('biometric_enroll_id')
                ->orderBy('employee_code')->get()
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'name' => $employee->fullName(),
                    'employee_code' => $employee->employee_code,
                ]),
        ]);
    }

    public function store(Request $request, ImportAttendancePunches $import): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            // ⚠ The device's own default export is a legacy binary `.xls`, so a
            // CSV-only rule made HR convert every file by hand before it could
            // be uploaded. `mimes` checks the real type, not the extension, and
            // Excel-written .xls/.xlsx both report as generic archives or OLE
            // containers often enough that `extensions` has to back it up.
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx,ods', 'extensions:csv,txt,xls,xlsx,ods', 'max:10240'],
            'source' => ['required', new EnumRule(PunchSource::class)],
            'device_identifier' => ['nullable', 'string', 'max:100'],
        ]);

        $batch = $import->handleFile(
            $request->file('file')->getRealPath(),
            $request->file('file')->getClientOriginalName(),
            PunchSource::from($validated['source']),
            $validated['device_identifier'] ?? null,
        );

        $message = sprintf(
            '%s: %d imported, %d duplicate, %d failed of %d rows.',
            $batch->filename,
            $batch->imported_count,
            $batch->duplicate_count,
            $batch->failed_count,
            $batch->total_rows,
        );

        // The screens change the moment the import lands now - say so, and
        // when the recompute stopped early, say exactly where and why.
        if ($batch->recomputedDays !== null) {
            $message .= sprintf(' %d day%s recomputed.', $batch->recomputedDays, $batch->recomputedDays === 1 ? '' : 's');
        }

        if ($batch->processingError !== null) {
            $message .= ' '.$batch->processingError;
        }

        return back()->with('success', $message);
    }
}
