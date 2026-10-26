<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\ApplyMovement;
use App\Modules\Hrm\Actions\CancelLeaveRequest;
use App\Modules\Hrm\Actions\CancelMovement;
use App\Modules\Hrm\Actions\GrantAnnualLeaveQuota;
use App\Modules\Hrm\Actions\GrantPaidLeaveOverride;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\RequestMovement;
use App\Modules\Hrm\Actions\YearEndLeaveProcessor;
use App\Modules\Hrm\Corrections\HrReasons;
use App\Modules\Hrm\Corrections\LeaveRequestActionPolicy;
use App\Modules\Hrm\Corrections\MovementActionPolicy;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Notifications\EarlyLeaveDecidedNotification;
use App\Modules\Hrm\Support\LeaveBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Leave and movement for HR: the request lists, the approvals, per-employee
 * balances, and the year-end preview.
 */
class LeaveController extends Controller
{
    use ValidatesActionReason;

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
                ->orderBy('employee_code')->get()
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
            // The Leave types tab edits rules, so it must also list INACTIVE
            // types - a type nobody can see is a type nobody can reactivate.
            // `leave_types` above stays active-only: it feeds the request form.
            'type_settings' => LeaveType::orderBy('name')->get()
                ->map(fn (LeaveType $t) => [
                    'id' => $t->id, 'name' => $t->name, 'code' => $t->code,
                    'annual_quota' => (float) $t->annual_quota,
                    'is_paid' => $t->is_paid,
                    'deducts_balance' => $t->deducts_balance,
                    'allows_half_day' => $t->allows_half_day,
                    'can_carry_forward' => $t->can_carry_forward,
                    'carry_forward_cap' => $t->carry_forward_cap !== null ? (float) $t->carry_forward_cap : null,
                    'effective_cap' => $t->can_carry_forward ? $t->effectiveCarryForwardCap() : null,
                    'can_encash' => $t->can_encash,
                    'is_active' => $t->is_active,
                    'is_system' => in_array($t->code, ['unpaid', LeaveType::COMPENSATORY], true),
                    // ⭐ B24 (25 Oct 2026): Delete when never used, else what uses it.
                    'used' => $t->whyNotRemovable(),
                ]),
            'portions' => collect(LeaveDayPortion::cases())
                ->map(fn (LeaveDayPortion $p) => ['value' => $p->value, 'label' => $p->label()]),
            'movement_types' => collect(MovementType::cases())
                ->map(fn (MovementType $t) => ['value' => $t->value, 'label' => $t->label()]),
            // ⭐ Corrections wave 3 (24 Oct 2026): the Cancel dialog's reasons.
            'reasons' => HrReasons::for(['cancellation', 'deletion', 'deactivation']),
        ]);
    }

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): Cancel an approved
     * leave. The days go back and the covered days are judged again; when that
     * month's payroll is approved it waits for a second person instead, and
     * the difference lands on the next open payroll (CancelLeaveRequest).
     */
    public function cancel(Request $request, LeaveRequest $leaveRequest, CancelLeaveRequest $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');
        $request->validate($this->actionReasonRules('cancellation'), $this->actionReasonMessages());

        try {
            $result = $action->handle($leaveRequest, $request->user(), $this->actionReason($request));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $result['outcome'] === 'requested'
            ? 'Payroll for those days is already approved, so the cancellation now waits for a second person. Nothing changes until they approve it.'
            : 'Leave cancelled. The days are back in the balance and the attendance was judged again.');
    }

    /** ⭐ Corrections wave 3: Cancel an approved movement (CancelMovement). */
    public function cancelMovement(Request $request, Movement $movement, CancelMovement $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');
        $request->validate($this->actionReasonRules('cancellation'), $this->actionReasonMessages());

        try {
            $result = $action->handle($movement, $request->user(), $this->actionReason($request));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $result['outcome'] === 'requested'
            ? 'Payroll for that day is already approved, so the cancellation now waits for a second person. Nothing changes until they approve it.'
            : 'Movement cancelled. The day was judged again without it.');
    }

    public function store(Request $request, RequestLeave $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            // ⚠ Active types only: a switched-off type is one the business no
            // longer grants, and the screen no longer offers it.
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('business_id', $this->businessId())->where('is_active', true)],
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

        // ⚠⚠ The final approval and applying it are one transaction, as in
        // LoanController::approve. Committed apart, a failure while applying
        // left the request approved and the decision never carried out.
        $complete = DB::transaction(function () use ($approval, $request, $note, $apply, $leaveRequest) {
            if (! $approval->approve($request->user(), $note)) {
                return false;
            }

            $apply->handle($leaveRequest);

            return true;
        });

        if (! $complete) {
            $notify->handle($approval->fresh(), $leaveRequest->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

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

    /**
     * ⭐ 27 Sep 2026 (Joy): the authority pays the unpaid days of an approved
     * leave, even when the employee had no paid balance for them. A
     * company-financed grant, so no quota moves; only the salary deduction
     * stops. The reason is mandatory, as with every money-affecting act.
     */
    public function grantPaid(Request $request, LeaveRequest $leaveRequest, GrantPaidLeaveOverride $action): RedirectResponse
    {
        $this->authorize('hrm.leave.manage');

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ])['reason'];

        $action->handle($leaveRequest, $request->user(), $reason);

        return back()->with('success', 'The leave is now paid by the authority; the salary deduction is removed.');
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

        $apply->handle($movement, $request->user()->id);
        $this->tellEarlyLeaveDecision($movement, approved: true, note: $note);

        return back()->with('success', $movement->type->isEarlyLeave()
            ? 'Early leave approved. The early departure carries no deduction and the day was judged again.'
            : 'Movement approved and the day reprocessed.');
    }

    /**
     * ⭐ Early leave (27 Oct 2026): the employee hears the decision either way.
     * Other movement types are unchanged.
     */
    private function tellEarlyLeaveDecision(Movement $movement, bool $approved, ?string $note): void
    {
        $user = $movement->employee?->user;

        if (! $movement->type->isEarlyLeave() || $user === null) {
            return;
        }

        $user->notify(new EarlyLeaveDecidedNotification(
            sprintf(
                'Your early leave request for %s was %s.%s',
                Carbon::parse($movement->movement_date)->format('j M Y'),
                $approved ? 'approved: no deduction for leaving early that day' : 'rejected: the day stays as the attendance rules judge it',
                filled($note) ? ' Note: '.$note : '',
            ),
            route('hrm.clock'),
        ));
    }

    public function rejectMovement(Request $request, Movement $movement): RedirectResponse
    {
        $approval = $movement->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $approval->reject($request->user(), $note);
        $this->tellEarlyLeaveDecision($movement, approved: false, note: $note);

        return back()->with('success', $movement->type->isEarlyLeave() ? 'Early leave rejected.' : 'Movement rejected.');
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

        // ⚠ Bug hunt 16 Oct 2026: a year that has not ended can be previewed,
        // never committed. Committed in October it lapsed and carried the
        // October balance, and the real run in January then did nothing.
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', $request->boolean('commit') ? 'max:'.(Carbon::now('Asia/Dhaka')->year - 1) : 'max:2100'],
            'commit' => ['required', 'boolean'],
        ], [
            'year.max' => 'That year has not ended yet. Its year-end can be committed from 1 January of the next year; a preview is fine now.',
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
        return app(BusinessContext::class)->id();
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
            'paid_by_authority' => $r->isPaidByAuthority(),
            'paid_override_reason' => $r->paid_override_reason,
            // ⭐ Corrections wave 3: cancelled after approval, and the buttons.
            'cancelled_at' => $r->cancelled_at?->format('d/m/Y'),
            'cancel_reason' => $r->cancel_reason,
            'actions' => app(LeaveRequestActionPolicy::class)->actionsFor($r, request()->user())->toArray(),
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
            'cancelled_at' => $m->cancelled_at?->format('d/m/Y'),
            'cancel_reason' => $m->cancel_reason,
            'actions' => app(MovementActionPolicy::class)->actionsFor($m, request()->user())->toArray(),
        ];
    }
}
