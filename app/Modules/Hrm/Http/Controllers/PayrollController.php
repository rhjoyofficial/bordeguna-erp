<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\RequestPayrollUnlock;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\Jobs\CalculatePayrollRunJob;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayrollUnlockRequest;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll calculation and the payslips it produces.
 *
 * The WFL-02 lifecycle beyond Draft/Calculated — review, approval, disbursement
 * and locking — belongs to Phase 3c, so this screen deliberately offers only
 * what it can actually enforce. Every payslip can explain itself, which is the
 * half of PAY-13 that matters most once someone queries their pay.
 */
class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.payroll.manage');

        // ⚠⚠ DEFAULTS TO THE NEWEST PAYROLL THERE IS, NOT TO TODAY. Landing on
        // the calendar month meant that on 1 September this screen opened
        // September - a month with no run - and answered "nothing calculated"
        // while an approved August payroll sat one click away. Joy hit exactly
        // that on cutover morning. A payroll month is not a calendar month, and
        // the useful default is the work in hand.
        $newest = $this->landingPeriod();

        $year = $request->integer('year') ?: (int) ($newest?->year ?? Carbon::today()->year);
        $month = $request->integer('month') ?: (int) ($newest?->month ?? Carbon::today()->month);

        $period = PayrollPeriod::where('year', $year)->where('month', $month)->first();
        $run = $period?->latestRun();

        return Inertia::render('hrm/payroll/index', [
            'year' => $year,
            'month' => $month,
            'period' => $period === null ? null : [
                'id' => $period->id,
                'label' => $period->label(),
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'divisor_days' => $period->divisor_days,
            ],
            'run' => $run === null ? null : [
                'id' => $run->id,
                'version' => $run->version,
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'employee_count' => $run->employee_count,
                'blocked_count' => $run->blocked_count,
                'total_earnings' => (float) $run->total_earnings,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'calculated_at' => $run->calculated_at?->format('Y-m-d H:i'),
                'calculated_by' => $run->calculator?->name,
                'approved_at' => $run->approved_at?->format('Y-m-d H:i'),
                'approved_by' => $run->approver?->name,
                'is_rerunnable' => $run->isRerunnable(),
                'is_reopenable' => $run->status->isReopenable(),
                'closed_reason' => $run->closed_reason,
                'queue_state' => $run->queue_state,
                'failure_message' => $run->failure_message,
                'validation_report' => $run->validation_report,
                'awaiting_approval' => $run->approvalRequest?->status->value === 'pending',
                'approval_stage' => $run->approvalRequest && $run->approvalRequest->totalStages() > 1
                    ? $run->approvalRequest->currentStage()?->name : null,
                'unlock_requests' => $run->unlockRequests()->with('requester:id,name')->orderByDesc('id')->get()
                    ->map(fn (PayrollUnlockRequest $u) => [
                        'id' => $u->id,
                        'reason' => $u->reason,
                        'is_emergency_override' => $u->is_emergency_override,
                        'override_reason' => $u->override_reason,
                        'is_applied' => $u->isApplied(),
                        'status' => $u->approvalRequest?->status->value ?? ($u->isApplied() ? 'applied' : 'pending'),
                        'stage' => $u->approvalRequest && $u->approvalRequest->totalStages() > 1
                            ? $u->approvalRequest->currentStage()?->name : null,
                        'requested_by' => $u->requester?->name,
                    ]),
            ],
            'payslips' => $run === null ? [] : $run->payslips()
                ->with(['employee:id,employee_code,first_name,last_name', 'lines'])
                ->get()
                ->sortBy(fn (Payslip $p) => $p->employee?->employee_code ?? '')
                ->values()
                ->map(fn (Payslip $payslip) => $this->present($payslip)),
            'periods' => PayrollPeriod::orderByDesc('year')->orderByDesc('month')->limit(12)->get()
                ->map(fn (PayrollPeriod $p) => [
                    'year' => $p->year,
                    'month' => $p->month,
                    'label' => $p->label(),
                ]),
        ]);
    }

    /**
     * The payroll month to land on: the newest one with work in it, and failing
     * that the newest month that exists at all.
     *
     * ⚠ A period with no run is not "the newest payroll" while a calculated one
     * exists - landing on an empty shell shows the same blank page the calendar
     * default did, which is the whole defect this replaced.
     *
     * ⚠⚠ But when NOTHING has been calculated yet, the newest period IS the
     * answer, and falling back to today is wrong. Joy cleared August on
     * 1 September to run it fresh; the screen then opened September, a month he
     * has no intention of running, with August one unexplained click away. The
     * first payroll a business ever runs is the case this has to get right.
     */
    private function landingPeriod(): ?PayrollPeriod
    {
        $newest = fn (?callable $filter = null) => PayrollPeriod::query()
            ->when($filter !== null, $filter)
            ->orderByDesc('year')->orderByDesc('month')
            ->first();

        return $newest(fn ($q) => $q->whereHas('runs')) ?? $newest();
    }

    /**
     * WFL-04 runs the calculation as a queued job. WFL-05 permits a free re-run
     * only while Draft or Calculated; anything beyond that needs a reopen.
     */
    public function calculate(Request $request): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $period = PayrollPeriod::forMonth($validated['year'], $validated['month']);
        $existing = $period->latestRun();

        // ⚠⚠ A VOIDED run (cancelled or rejected) is allowed straight through:
        // it is a thrown-away version, not a decision to protect, and
        // CalculatePayroll::openRun answers it with a fresh version+1 (WFL-06)
        // while the void one stays as the record. This guard used to refuse
        // both, which made the period a DEAD END - neither state is
        // reopenable, so "reopen it first" named a door that does not exist.
        // It stranded Joy's real August payroll twice on 24 Aug 2026.
        if ($existing !== null && ! $existing->isRerunnable() && ! $existing->status->isVoided()) {
            // Say what to actually do, which differs by state: before the money
            // moves a reopen re-versions (WFL-06); after it, corrections ride
            // the next payroll as an arrear (WFL-05) and no re-run exists.
            return back()->with('error', $existing->status->isReopenable()
                ? sprintf('This payroll is %s. Reopen it first - WFL-06 turns a reopen into a new version.', $existing->status->label())
                : sprintf('This payroll is %s and the figures are the record. WFL-05 allows no re-run; a correction goes to the next payroll as an arrear.', $existing->status->label()));
        }

        CalculatePayrollRunJob::dispatch(
            $this->businessId(),
            $period->id,
            $request->user()->id,
        );

        return back()->with('success', sprintf('%s payroll queued. Progress appears here as it runs.', $period->label()));
    }

    /**
     * Progress for a queued run, polled by the screen while it works.
     */
    public function progress(PayrollRun $run): JsonResponse
    {
        $this->authorize('hrm.payroll.manage');

        return response()->json([
            ...$run->progress(),
            'status' => $run->status->value,
            'queue_state' => $run->queue_state,
            'failure_message' => $run->failure_message,
        ]);
    }

    /**
     * WFL-02: review sends the run into the shared approval pipeline.
     */
    public function review(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $transition->review($run, $request->user());

        return back()->with('success', 'Reviewed and sent for approval.');
    }

    public function approve(Request $request, PayrollRun $run, TransitionPayrollRun $transition, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $run->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), 'Payroll '.$run->period?->label());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $transition->markApproved($run, $request->user());

        return back()->with('success', 'Payroll approved.');
    }

    public function reject(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $approval = $run->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $approval->reject($request->user(), $note);
        $transition->markRejected($run, $request->user(), $note);

        return back()->with('success', 'Payroll rejected.');
    }

    public function disburse(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $transition->disburse($run, $request->user());

        return back()->with('success', 'Marked as disbursed.');
    }

    public function lock(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $transition->lock($run, $request->user());

        return back()->with('success', 'Payroll locked.');
    }

    /**
     * WFL-06: reopening before disbursement produces a new version.
     */
    public function reopen(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $next = $transition->reopen($run, $request->user(), $reason);

        return back()->with('success', sprintf('Reopened as version %d.', $next->version));
    }

    public function cancel(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $transition->cancel($run, $request->user(), $reason);

        return back()->with('success', 'Payroll cancelled.');
    }

    /**
     * WFL-08: request to reopen a locked payroll, through HR Head → Finance →
     * Management, or a Super Admin emergency override with a stated reason.
     */
    public function requestUnlock(Request $request, PayrollRun $run, RequestPayrollUnlock $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'is_emergency_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $unlock = $action->handle(
            $run,
            $request->user(),
            $validated['reason'],
            (bool) ($validated['is_emergency_override'] ?? false),
            $validated['override_reason'] ?? null,
        );

        return back()->with('success', $unlock->isApplied()
            ? 'Emergency override applied — the payroll has been reopened and the override is on record.'
            : 'Unlock requested. It needs HR Head, Finance and Management approval.');
    }

    public function approveUnlock(Request $request, PayrollUnlockRequest $unlock, RequestPayrollUnlock $action, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $unlock->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), $unlock->summaryLine());

            return back()->with('success', 'Approved. Sent to the next authority.');
        }

        $action->apply($unlock);

        return back()->with('success', 'Unlock approved — the payroll has been reopened as a new version.');
    }

    public function rejectUnlock(Request $request, PayrollUnlockRequest $unlock): RedirectResponse
    {
        $approval = $unlock->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $approval->reject($request->user(), $note);

        return back()->with('success', 'Unlock request rejected.');
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Payslip $payslip): array
    {
        return [
            'id' => $payslip->id,
            'employee' => $payslip->employee?->fullName(),
            'employee_code' => $payslip->employee?->employee_code,
            'gross_salary' => (float) $payslip->gross_salary,
            'total_earnings' => (float) $payslip->total_earnings,
            'total_deductions' => (float) $payslip->total_deductions,
            'net' => (float) $payslip->net,
            'is_blocked' => $payslip->is_blocked,
            'block_reasons' => $payslip->block_reasons ?? [],
            'notes' => $payslip->explanation(),
            'lines' => $payslip->lines->map(fn (PayslipLine $line) => [
                'code' => $line->code,
                'label' => $line->label,
                'type' => $line->type->value,
                'amount' => (float) $line->amount,
                'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                'rate' => $line->rate === null ? null : (float) $line->rate,
                'formula' => $line->formula,
                'source' => $line->source,
            ]),
        ];
    }
}
