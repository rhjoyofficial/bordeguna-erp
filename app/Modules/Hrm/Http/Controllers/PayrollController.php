<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\PostPayrollAccrual;
use App\Modules\Hrm\Actions\RequestPayrollUnlock;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Jobs\CalculatePayrollRunJob;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayrollUnlockRequest;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Modules\Hrm\Support\PayrollProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
                'locked_at' => $run->locked_at?->format('j M Y'),
                'locked_by' => $run->locker?->name,
                'is_rerunnable' => $run->isRerunnable(),
                'is_reopenable' => $run->status->isReopenable(),
                'closed_reason' => $run->closed_reason,
                // Approved before accounting was switched on: the salary cost
                // never reached the books (run 10, September 2026).
                'accrual_missing' => app(PostPayrollAccrual::class)->isMissingFor($run),
                'queue_state' => $run->queue_state,
                'failure_message' => $run->failure_message,
                'validation_report' => $run->validation_report,
                'awaiting_approval' => $run->approvalRequest?->status->value === 'pending',
                'approval_stage' => $run->approvalRequest && $run->approvalRequest->totalStages() > 1
                    ? $run->approvalRequest->currentStage()?->name : null,
                // Whether THIS user may decide the pending stage. The screen
                // used to offer Approve to everyone and answer with a 403.
                'can_approve' => $run->approvalRequest?->canBeActionedBy($request->user()) ?? false,
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
                ->with(['employee:id,employee_code,first_name,last_name,salary_payment_method', 'lines', 'payments'])
                ->get()
                ->sortBy(fn (Payslip $p) => $p->employee?->employee_code ?? '')
                ->values()
                ->map(fn (Payslip $payslip) => $this->present($payslip)),
            // Every payroll month with the state of its latest version, for the
            // month switcher's grid (15 Oct 2026). All of them, not the last
            // twelve: the grid is one year at a time and a locked month two
            // years back is still a month somebody may need to open.
            'periods' => PayrollPeriod::with('runs')->orderByDesc('year')->orderByDesc('month')->get()
                ->map(function (PayrollPeriod $p) {
                    $latest = $p->runs->first();

                    return [
                        'year' => $p->year,
                        'month' => $p->month,
                        'label' => $p->label(),
                        'status' => $latest?->status->value,
                        'status_label' => $latest?->status->label(),
                        'queue_state' => $latest?->queue_state,
                        'awaiting_approval' => $latest?->approvalRequest?->status->value === 'pending',
                    ];
                }),
            // Months before the first payroll have nothing to open; months
            // after the cycle running today have not started.
            'first_period' => ($first = PayrollPeriod::orderBy('year')->orderBy('month')->first()) === null
                ? null
                : ['year' => $first->year, 'month' => $first->month],
            'current_cycle' => (function () {
                $cycle = PayrollCycle::containing(Carbon::today());

                return ['year' => $cycle['year'], 'month' => $cycle['month']];
            })(),
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
    public function calculate(Request $request, CalculatePayroll $calculate, PayrollProgress $progress): RedirectResponse
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

        // ⚠⚠ THE RUN IS OPENED HERE, NOT IN THE JOB (15 Oct 2026). It used to
        // be born inside the queued job, so after pressing Calculate the page
        // had no run to poll and showed nothing until somebody refreshed. The
        // Draft opened here is rerunnable, so the job's own openRun lands on
        // this same row. `queued` is the state the screen watches: the job
        // overwrites it with done, blocked or failed, and until then the page
        // polls the progress route for this id.
        $run = $calculate->openRun($period, PayrollConfig::defaults());
        $run->update(['queue_state' => 'queued', 'failure_message' => null]);
        // A stale "done" from the previous calculation of this same run would
        // end the new poll on its first tick.
        $progress->clear($run);

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

        // ⚠⚠ One transaction, and the run is checked BEFORE the stage decision
        // is written. A cancelled or superseded version must be refused at
        // every stage, and a refusal at the final stage must not leave the
        // request approved while the run is not.
        $complete = DB::transaction(function () use ($approval, $run, $transition, $request, $note) {
            $transition->lockAwaitingApproval($run);

            if (! $approval->approve($request->user(), $note)) {
                return false;
            }

            $transition->markApproved($run, $request->user());

            return true;
        });

        if (! $complete) {
            $notify->handle($approval->fresh(), 'Payroll '.$run->period?->label());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        return back()->with('success', 'Payroll approved.');
    }

    public function reject(Request $request, PayrollRun $run, TransitionPayrollRun $transition): RedirectResponse
    {
        $approval = $run->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        DB::transaction(function () use ($approval, $run, $transition, $request, $note) {
            $approval->reject($request->user(), $note);
            $transition->markRejected($run, $request->user(), $note);
        });

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
     * Posts the salary cost of a run that was approved before accounting was
     * switched on. Same action, same date (the period end) as the approval
     * would have used; idempotent, so a second press posts nothing.
     */
    public function postAccrual(Request $request, PayrollRun $run, PostPayrollAccrual $accrual): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        if (! $accrual->isMissingFor($run)) {
            return back()->with('success', 'Nothing to post: the salary cost of this payroll is already in the books, or accounting is not switched on for it.');
        }

        try {
            $journal = $accrual->handle($run, $request->user());
        } catch (PeriodClosedException|MappingNotFound|JournalException $exception) {
            throw ValidationException::withMessages(['accrual' => $exception->getMessage()]);
        }

        return back()->with('success', $journal === null
            ? 'Nothing to post for this payroll.'
            : sprintf('Salary cost posted to the books as %s, dated %s.', $journal->journal_number, $journal->journal_date->toDateString()));
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
            // How and how much of it has actually been paid, for the "Paid"
            // total and the "Paid by" column (15 Oct 2026).
            'method' => ($payslip->employee?->salary_payment_method ?? SalaryPaymentMethod::Cash)->label(),
            'paid' => $payslip->settledTotal(),
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
