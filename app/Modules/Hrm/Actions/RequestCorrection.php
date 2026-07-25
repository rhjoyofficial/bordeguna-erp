<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a correction request (COR-01/02/03/04).
 *
 * Two entry paths, one record. An employee raises a request that routes through
 * the workflow (Manager, then HR); someone who already holds the attendance
 * permission is exercising the "privileged HR correction" COR-01 allows, so
 * their correction applies immediately — still recorded, still attributed,
 * still activity-logged, which is the "both audited" half of that answer.
 *
 * A locked period is not a normal path (Correction C1): correcting inside one
 * requires the attendance permission *and* a stated override reason, so a
 * reopened judgement always carries its own justification.
 */
class RequestCorrection
{
    public function __construct(
        private readonly ApplyCorrection $apply,
        private readonly NotifyStageApprovers $notify,
        private readonly StoreAttachment $storeAttachment,
    ) {}

    /**
     * @param  array<string, mixed>  $data  type/punch_id/corrected_at/direction/reason/override_reason
     *
     * @throws ValidationException
     */
    public function handle(Employee $employee, string $date, array $data, User $requestedBy, ?UploadedFile $evidence = null): AttendanceCorrection
    {
        $privileged = $requestedBy->can('hrm.attendance.manage');

        $this->guardLockedPeriod($date, $data, $privileged);

        return DB::transaction(function () use ($employee, $date, $data, $requestedBy, $evidence, $privileged) {
            $correction = AttendanceCorrection::create([
                'employee_id' => $employee->id,
                'attendance_date' => $date,
                'type' => $data['type'],
                'punch_id' => $data['punch_id'] ?? null,
                'corrected_at' => $data['corrected_at'] ?? null,
                'direction' => $data['direction'] ?? null,
                'reason' => $data['reason'],
                'override_reason' => $data['override_reason'] ?? null,
                'requested_by' => $requestedBy->id,
            ]);

            // COR-04: evidence is optional, and rides on the existing attachment
            // engine rather than a bespoke column.
            if ($evidence !== null) {
                $this->storeAttachment->handle($correction, $evidence, 'evidence');
            }

            if ($privileged) {
                $this->apply->handle($correction);

                return $correction->fresh();
            }

            $request = $correction->submitForApproval($requestedBy);
            $this->notify->handle($request, $correction->summaryLine());

            return $correction->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function guardLockedPeriod(string $date, array $data, bool $privileged): void
    {
        $lock = AttendancePeriodLock::covering($date);

        if ($lock === null) {
            return;
        }

        if (! $privileged) {
            throw ValidationException::withMessages([
                'attendance_date' => 'This period is locked. Ask HR to correct it — a locked period needs an authorised override.',
            ]);
        }

        if (blank($data['override_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'override_reason' => 'This period is locked, so an override reason is required (Correction C1).',
            ]);
        }
    }
}
