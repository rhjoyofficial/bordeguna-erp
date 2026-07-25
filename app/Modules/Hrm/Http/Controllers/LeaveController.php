<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\ApplyMovement;
use App\Modules\Hrm\Actions\GrantAnnualLeaveQuota;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\RequestMovement;
use App\Modules\Hrm\Actions\YearEndLeaveProcessor;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Support\LeaveBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave and movement for HR: the request lists, the approvals, per-employee
 * balances, and the year-end preview.
 */
class LeaveController extends Controller
{
    public function index(Request $request, LeaveBalance $balances): Response
    {
        $this->authorize('hrm.leave.manage');

        $year = $request->integer('year') ?: (int) Carbon::today()->year;
        $employeeId = $request->integer('employee_id') ?: null;

        return Inertia::render('hrm/leave/index', [
            'year' => $year,
            'filters' => ['employee_id' => $employeeId],
            'requests' => LeaveRequest::with(['employee:id,employee_code,first_name,last_name', 'leaveType:id,name', 'approvalRequest.workflow.stages'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (LeaveRequest $r) => $this->presentLeave($r)),
            'movements' => Movement::with(['employee:id,employee_code,first_name,last_name', 'approvalRequest.workflow.stages'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn (Movement $m) => $this->presentMovement($m)),
            'balances' => $employeeId ? $balances->summaryFor($employeeId, $year) : collect(),
            'ledger' => $employeeId
                ? $balances->entriesFor($employeeId, $year)->map(fn ($entry) => [
                    'id' => $entry->id,
                    'date' => Carbon::parse($entry->occurred_on)->toDateString(),
                    'type' => $entry->leaveType?->name,
                    'entry_type' => $entry->entry_type->label(),
                    'days' => (float) $entry->days,
                    'note' => $entry->note,
                ])
                : collect(),
            'employees' => Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
                ->orderBy('first_name')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName().' ('.$e->employee_code.')']),
            'leave_types' => LeaveType::active()->orderBy('name')->get()
                ->map(fn (LeaveType $t) => [
                    'id' => $t->id, 'name' => $t->name, 'code' => $t->code,
                    'allows_half_day' => $t->allows_half_day,
                    'deducts_balance' => $t->deducts_balance,
                    'can_carry_forward' => $t->can_carry_forward,
                    'can_encash' => $t->can_encash,
                    'annual_quota' => (float) $t->annual_quota,
                    'carry_forward_cap' => $t->can_carry_forward ? $t->effectiveCarryForwardCap() : null,
                ]),
            'portions' => collect(LeaveDayPortion::cases())
                ->map(fn (LeaveDayPortion $p) => ['value' => $p->value, 'label' => $p->label()]),
            'movement_types' => collect(MovementType::cases())
                ->map(fn (MovementType $t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    public function store(Request $request, RequestLeave $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('business_id', $this->businessId())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'day_portion' => ['required', new EnumRule(LeaveDayPortion::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->handle(
            Employee::findOrFail($validated['employee_id']),
            LeaveType::findOrFail($validated['leave_type_id']),
            $validated['start_date'],
            $validated['end_date'],
            LeaveDayPortion::from($validated['day_portion']),
            $validated['reason'],
            $request->user(),
        );

        return back()->with('success', 'Leave requested.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, ApplyLeave $apply, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $leaveRequest->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), $leaveRequest->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $apply->handle($leaveRequest);

        return back()->with('success', 'Leave approved and the balance updated.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $approval = $leaveRequest->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $approval->reject($request->user(), $note);

        return back()->with('success', 'Leave rejected.');
    }

    public function storeMovement(Request $request, RequestMovement $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            'type' => ['required', new EnumRule(MovementType::class)],
            'movement_date' => ['required', 'date'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i', 'after:from_time'],
            'place' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->handle(
            Employee::findOrFail($validated['employee_id']),
            MovementType::from($validated['type']),
            $validated['movement_date'],
            $validated,
            $request->user(),
        );

        return back()->with('success', 'Movement requested.');
    }

    public function approveMovement(Request $request, Movement $movement, ApplyMovement $apply, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $movement->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), $movement->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $apply->handle($movement);

        return back()->with('success', 'Movement approved and the day reprocessed.');
    }

    public function rejectMovement(Request $request, Movement $movement): RedirectResponse
    {
        $approval = $movement->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $approval->reject($request->user(), $note);

        return back()->with('success', 'Movement rejected.');
    }

    /**
     * LEV-02's annual grant. Keyed, so pressing it twice grants once.
     */
    public function grantQuota(Request $request, GrantAnnualLeaveQuota $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']])['year'];
        $result = $action->handle($year);

        return back()->with('success', sprintf(
            'Granted %d quota entries for %d. %d were already present.',
            $result['granted'], $year, $result['skipped'],
        ));
    }

    /**
     * Corrections C6b/C6d. The preview and the commit share one computation, so
     * what HR reviews here is exactly what gets posted.
     */
    public function yearEnd(Request $request, YearEndLeaveProcessor $processor): Response
    {
        $this->authorize('hrm.leave.manage');

        $year = $request->integer('year') ?: (int) Carbon::today()->subYear()->year;
        $plan = $processor->plan($year);

        return Inertia::render('hrm/leave/year-end', [
            'year' => $year,
            'plan' => $plan['rows'],
            'totals' => $plan['totals'],
            'runs' => LeaveYearEndRun::with('runner:id,name')->where('year', $year)
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn (LeaveYearEndRun $run) => [
                    'id' => $run->id,
                    'is_dry_run' => $run->is_dry_run,
                    'employees_affected' => $run->employees_affected,
                    'total_carried' => (float) $run->total_carried,
                    'total_lapsed' => (float) $run->total_lapsed,
                    'run_by' => $run->runner?->name,
                    'run_at' => $run->created_at?->format('Y-m-d H:i'),
                ]),
        ]);
    }

    public function runYearEnd(Request $request, YearEndLeaveProcessor $processor): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'commit' => ['required', 'boolean'],
        ]);

        $run = $validated['commit']
            ? $processor->commit($validated['year'])
            : $processor->preview($validated['year']);

        return back()->with('success', sprintf(
            '%s for %d: %s day(s) carried, %s day(s) lapsed across %d employee(s).',
            $validated['commit'] ? 'Committed' : 'Previewed',
            $validated['year'],
            $run->total_carried,
            $run->total_lapsed,
            $run->employees_affected,
        ));
    }

    private function businessId(): int
    {
        return app(\App\Modules\Foundation\Support\BusinessContext::class)->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLeave(LeaveRequest $r): array
    {
        $approval = $r->approvalRequest;

        return [
            'id' => $r->id,
            'employee' => $r->employee?->fullName(),
            'employee_code' => $r->employee?->employee_code,
            'leave_type' => $r->leaveType?->name,
            'start_date' => Carbon::parse($r->start_date)->toDateString(),
            'end_date' => Carbon::parse($r->end_date)->toDateString(),
            'days' => (float) $r->days,
            'unpaid_days' => (float) $r->unpaid_days,
            'reason' => $r->reason,
            'status' => $approval?->status->value ?? 'pending',
            'stage' => $approval && $approval->totalStages() > 1 ? $approval->currentStage()?->name : null,
            'is_applied' => $r->isApplied(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMovement(Movement $m): array
    {
        $approval = $m->approvalRequest;

        return [
            'id' => $m->id,
            'employee' => $m->employee?->fullName(),
            'type' => $m->type->label(),
            'movement_date' => Carbon::parse($m->movement_date)->toDateString(),
            'summary' => $m->summaryLine(),
            'reason' => $m->reason,
            'status' => $approval?->status->value ?? 'pending',
            'is_applied' => $m->isApplied(),
        ];
    }
}
