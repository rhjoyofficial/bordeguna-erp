<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplySalaryRevision;
use App\Modules\Hrm\Actions\RequestSalaryRevision;
use App\Modules\Hrm\Corrections\HrReasons;
use App\Modules\Hrm\Corrections\PayrollInputCancelPolicy;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Salary\ResolvedSalaryLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Increments (ADJ-06) and the arrears a retroactive one produces (SAL-05).
 *
 * Separate from the salary-structure screen on purpose. That screen answers
 * "what is this person paid"; this one answers "what is being *asked* for, and
 * by whom" — a proposal is a different object from a fact, which is exactly what
 * ADJ-06 insisted on when it ticked Proposal → Approval → Effective revision and
 * left HR direct entry blank.
 *
 * Read access follows Correction C5 / ACL-02 like every other salary screen:
 * `hrm.salary.manage` to propose, `hrm.salary.view` to read. Department Managers
 * hold neither.
 */
class SalaryRevisionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRead($request);

        $employeeId = $request->integer('employee_id') ?: null;

        return Inertia::render('hrm/salary/revisions', [
            'can_manage' => (bool) $request->user()?->can('hrm.salary.manage'),
            'filters' => ['employee_id' => $employeeId],
            'revisions' => SalaryRevision::with(['employee:id,employee_code,first_name,last_name', 'approvalRequest', 'requester:id,name'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (SalaryRevision $r) => [
                    'id' => $r->id,
                    'employee' => $r->employee?->fullName(),
                    'employee_code' => $r->employee?->employee_code,
                    'effective_from' => Carbon::parse($r->effective_from)->toDateString(),
                    'requested_effective_from' => $r->requested_effective_from?->toDateString(),
                    'is_retroactive' => $r->isRetroactive(),
                    'proposed_total' => $r->proposedTotal(),
                    'overtime_eligible' => (bool) $r->overtime_eligible,
                    'reason' => $r->reason,
                    'status' => $r->approvalRequest?->status->value ?? 'pending',
                    'is_applied' => $r->isApplied(),
                    'arrear_amount' => (float) $r->arrear_amount,
                    'arrear_breakdown' => $r->arrear_breakdown ?? [],
                    'requested_by' => $r->requester?->name,
                    // ⭐ B24 (25 Oct 2026): Cancel after approval, and its mark.
                    'cancelled' => $r->cancelled_at === null ? null : ['at' => Carbon::parse($r->cancelled_at)->toDateString(), 'reason' => $r->cancel_reason],
                    'actions' => app(PayrollInputCancelPolicy::class)->actions($r, $request->user()),
                ]),
            'employees' => Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
                ->orderBy('employee_code')->get()
                ->map(function (Employee $e) {
                    $structure = SalaryStructure::activeFor($e->id);
                    $breakdown = $structure?->breakdown();

                    return [
                        'id' => $e->id,
                        'name' => $e->fullName().' ('.$e->employee_code.')',
                        'has_structure' => $structure !== null,
                        'overtime_eligible' => (bool) ($structure?->overtime_eligible ?? false),
                        'effective_from' => $structure ? Carbon::parse($structure->effective_from)->toDateString() : null,
                        'basic' => $breakdown?->basic,
                        'gross' => $breakdown?->gross,
                        // Pre-fills the proposal form with what they earn now, so
                        // a raise is edited rather than retyped from nothing.
                        'lines' => collect($breakdown?->lines ?? [])->map(fn (ResolvedSalaryLine $line) => [
                            'salary_component_id' => $line->componentId,
                            'value' => $line->value,
                        ])->values(),
                    ];
                }),
            'reasons' => HrReasons::for(['cancellation']),
            'components' => SalaryComponent::active()->inPayslipOrder()->get()
                ->map(fn (SalaryComponent $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'type' => $c->type->value,
                    'is_basic' => $c->is_basic,
                    'basis_label' => $c->calculation_basis->label(),
                    'is_percentage' => $c->calculation_basis->isPercentage(),
                    // C14, same reason as SalaryController::presentComponent —
                    // a revision screen must not present a daily rate as if it
                    // were a monthly amount.
                    'is_priced_by_attendance' => $c->calculation_basis->isPricedByAttendance(),
                ]),
        ]);
    }

    public function store(Request $request, RequestSalaryRevision $action): RedirectResponse
    {
        $this->authorize('hrm.salary.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            // SAL-05 allows a retroactive date, so no `after:today` rule here —
            // reaching backwards is the point, and the arrear is the consequence.
            'effective_from' => ['required', 'date'],
            // Rule #16, 14 Oct 2026: the mid-cycle date first typed, sent back
            // when the proposer accepts the offered cycle start.
            'requested_effective_from' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
            'overtime_eligible' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.salary_component_id' => ['required', 'integer', Rule::exists('salary_components', 'id')->where('business_id', $this->businessId())],
            'lines.*.value' => ['required', 'numeric', 'min:0'],
        ]);

        $action->handle(
            Employee::findOrFail($validated['employee_id']),
            $validated['effective_from'],
            $validated['lines'],
            $validated['reason'],
            $request->user(),
            (bool) ($validated['overtime_eligible'] ?? false),
            $validated['requested_effective_from'] ?? null,
        );

        return back()->with('success', 'Revision proposed and sent for approval.');
    }

    public function approve(
        Request $request,
        SalaryRevision $revision,
        ApplySalaryRevision $apply,
        NotifyStageApprovers $notify,
    ): RedirectResponse {
        $approval = $revision->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        // ⚠⚠ The final approval and applying it are one transaction, as in
        // LoanController::approve. Committed apart, a failure while applying
        // left the request approved and the decision never carried out.
        $applied = DB::transaction(function () use ($approval, $request, $note, $apply, $revision) {
            if (! $approval->approve($request->user(), $note)) {
                return null;
            }

            return $apply->handle($revision);
        });

        if ($applied === null) {
            $notify->handle($approval->fresh(), $revision->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $arrear = (float) $applied->arrear_amount;

        return back()->with('success', abs($arrear) < 0.01
            ? 'Revision approved and the new salary structure is in force.'
            : sprintf(
                'Revision approved. %s of %s posted to the next open payroll (SAL-05).',
                $arrear > 0 ? 'An arrear' : 'A recovery',
                number_format(abs($arrear), 2),
            ));
    }

    public function reject(Request $request, SalaryRevision $revision): RedirectResponse
    {
        $approval = $revision->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $approval->reject($request->user(), $note);

        return back()->with('success', 'Revision rejected. No salary structure was created.');
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless(
            $request->user()?->can('hrm.salary.manage') || $request->user()?->can('hrm.salary.view'),
            403,
        );
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }
}
