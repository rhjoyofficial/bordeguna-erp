<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Actions\ApplyCorrection;
use App\Modules\Hrm\Actions\RequestCorrection;
use App\Modules\Hrm\Enums\CorrectionType;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance corrections (COR-01..06) and the period lock they respect.
 *
 * Approve/reject live here rather than on the generic change-request endpoint
 * because a correction is its own approvable type with its own applier, exactly
 * like stock requests and purchase orders.
 */
class AttendanceCorrectionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.attendance.manage');

        $corrections = AttendanceCorrection::with([
            'employee:id,employee_code,first_name,last_name',
            'punch:id,punched_at',
            'requester:id,name',
            'approvalRequest.workflow.stages',
        ])->orderByDesc('id')->limit(100)->get();

        return Inertia::render('hrm/attendance/corrections', [
            'corrections' => $corrections->map(fn (AttendanceCorrection $c) => $this->present($c)),
            'locks' => AttendancePeriodLock::with(['locker:id,name', 'unlocker:id,name'])
                ->orderByDesc('period_start')->limit(25)->get()
                ->map(fn (AttendancePeriodLock $lock) => [
                    'id' => $lock->id,
                    'period_start' => Carbon::parse($lock->period_start)->toDateString(),
                    'period_end' => Carbon::parse($lock->period_end)->toDateString(),
                    'note' => $lock->note,
                    'is_active' => $lock->isActive(),
                    'locked_by' => $lock->locker?->name,
                    'unlocked_by' => $lock->unlocker?->name,
                    'unlock_reason' => $lock->unlock_reason,
                ]),
            'types' => collect(CorrectionType::cases())
                ->map(fn (CorrectionType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'needs_punch' => $t->needsPunch(),
                    'needs_time' => $t->needsTime(),
                ]),
            'directions' => collect(PunchDirection::cases())
                ->map(fn (PunchDirection $d) => ['value' => $d->value, 'label' => $d->label()]),
            'employees' => Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
                ->orderBy('first_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName().' ('.$e->employee_code.')']),
        ]);
    }

    /**
     * Punches on a day, so the correction form can point at a real one rather
     * than asking someone to type an id.
     */
    public function punches(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        return response()->json(
            AttendancePunch::where('employee_id', $validated['employee_id'])
                ->onDate($validated['date'])
                ->orderBy('punched_at')
                ->get()
                ->map(fn (AttendancePunch $punch) => [
                    'id' => $punch->id,
                    'label' => $punch->punched_at->format('H:i:s').' · '.($punch->direction?->label() ?? 'unknown').' · '.$punch->source->label(),
                ])
        );
    }

    public function store(Request $request, RequestCorrection $action): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $employee = Employee::findOrFail($request->integer('employee_id'));

        $action->handle(
            $employee,
            $request->date('attendance_date')->toDateString(),
            $this->validated($request, $employee),
            $request->user(),
            $request->file('evidence'),
        );

        return back()->with('success', 'Correction recorded and applied.');
    }

    public function approve(Request $request, AttendanceCorrection $correction, ApplyCorrection $apply, NotifyStageApprovers $notify): RedirectResponse
    {
        $approvalRequest = $correction->approvalRequest;
        abort_if($approvalRequest === null, 404);
        abort_unless($approvalRequest->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        // True once the final stage has approved; until then the request simply
        // advances and the next stage's approvers are told.
        $complete = $approvalRequest->approve($request->user(), $note);

        if (! $complete) {
            $notify->handle($approvalRequest->fresh(), $correction->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $apply->handle($correction);

        return back()->with('success', 'Correction approved and the day reprocessed.');
    }

    public function reject(Request $request, AttendanceCorrection $correction): RedirectResponse
    {
        $approvalRequest = $correction->approvalRequest;
        abort_if($approvalRequest === null, 404);
        abort_unless($approvalRequest->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $approvalRequest->reject($request->user(), $note);

        return back()->with('success', 'Correction rejected.');
    }

    /**
     * COR-05: close a period. Corrections inside it then need the audited
     * override of Correction C1.
     */
    public function lock(Request $request): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        AttendancePeriodLock::create([
            ...$validated,
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Period locked.');
    }

    /**
     * Reopening is recorded rather than deleted, so a period that was locked
     * and then reopened still shows who did it and why.
     */
    public function unlock(Request $request, AttendancePeriodLock $lock): RedirectResponse
    {
        $this->authorize('hrm.attendance.manage');

        $validated = $request->validate([
            'unlock_reason' => ['required', 'string', 'max:255'],
        ]);

        $lock->update([
            'unlocked_at' => now(),
            'unlocked_by' => $request->user()->id,
            'unlock_reason' => $validated['unlock_reason'],
        ]);

        return back()->with('success', 'Period reopened.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Employee $employee): array
    {
        $type = CorrectionType::tryFrom($request->string('type')->toString());

        return $request->validate([
            'type' => ['required', new EnumRule(CorrectionType::class)],
            'punch_id' => [
                $type?->needsPunch() ? 'required' : 'nullable',
                Rule::exists('attendance_punches', 'id')->where('employee_id', $employee->id),
            ],
            'corrected_at' => [$type?->needsTime() ? 'required' : 'nullable', 'date'],
            'direction' => ['nullable', new EnumRule(PunchDirection::class)],
            'reason' => ['required', 'string', 'max:1000'],
            'override_reason' => ['nullable', 'string', 'max:255'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AttendanceCorrection $correction): array
    {
        $approval = $correction->approvalRequest;

        return [
            'id' => $correction->id,
            'employee' => $correction->employee?->fullName(),
            'employee_code' => $correction->employee?->employee_code,
            'attendance_date' => Carbon::parse($correction->attendance_date)->toDateString(),
            'type' => $correction->type->label(),
            'summary' => $correction->summaryLine(),
            'reason' => $correction->reason,
            'override_reason' => $correction->override_reason,
            'requested_by' => $correction->requester?->name,
            'status' => $approval?->status->value ?? ($correction->isApplied() ? 'applied' : 'pending'),
            'stage' => $approval && $approval->totalStages() > 1 ? $approval->currentStage()?->name : null,
            'is_applied' => $correction->isApplied(),
            'applied_at' => $correction->applied_at?->format('Y-m-d H:i'),
        ];
    }
}
