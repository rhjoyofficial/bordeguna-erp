<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyOvertimeAssignment;
use App\Modules\Hrm\Actions\AwardBonus;
use App\Modules\Hrm\Actions\CarryForwardNegativeNet;
use App\Modules\Hrm\Actions\RequestOvertime;
use App\Modules\Hrm\Actions\RequestPayrollAdjustment;
use App\Modules\Hrm\Corrections\PayrollAdjustmentActionPolicy;
use App\Modules\Hrm\Corrections\PayrollInputCancelPolicy;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\BonusEvent;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Support\PayrollBalanceLedger;
use App\Modules\Hrm\Support\PayrollInputWindow;
use App\Modules\Purchasing\Support\CorrectionReasons;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The things payroll pays that the salary structure does not describe: overtime
 * (ADJ-01..04), bonuses (ADJ-05), manual adjustments (ADJ-08/09), and the PAY-11
 * carry-forward balances.
 *
 * They share one screen because they share one question — "what else is going on
 * this month" — and because HR reviewing a payroll wants to see all of it at
 * once rather than open four pages to find out why a net moved.
 *
 * Nothing here calculates anything. Each action records an authorisation and
 * routes it for approval; the engine finds them at the next run. That separation
 * is the whole reason Phase 3d needed no engine rewrite.
 */
class PayrollInputController extends Controller
{
    public function index(Request $request, PayrollBalanceLedger $ledger): Response
    {
        $this->authorize('hrm.payroll.manage');

        $employeeId = $request->integer('employee_id') ?: null;
        $periodId = $request->integer('payroll_period_id') ?: null;
        $adjustmentPolicy = app(PayrollAdjustmentActionPolicy::class);
        // ⭐ B24 (25 Oct 2026): Cancel an approved overtime or bonus award.
        $inputPolicy = app(PayrollInputCancelPolicy::class);
        $cancelled = fn ($row) => $row->cancelled_at === null ? null : ['at' => Carbon::parse($row->cancelled_at)->toDateString(), 'reason' => $row->cancel_reason];

        return Inertia::render('hrm/payroll/inputs', [
            'filters' => ['employee_id' => $employeeId, 'payroll_period_id' => $periodId],
            'overtime' => OvertimeAssignment::with(['employee:id,employee_code,first_name,last_name', 'approvalRequest'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->orderByDesc('work_date')->orderByDesc('id')->limit(100)->get()
                ->map(fn (OvertimeAssignment $a) => [
                    'id' => $a->id,
                    'employee' => $a->employee?->fullName(),
                    'employee_code' => $a->employee?->employee_code,
                    'work_date' => Carbon::parse($a->work_date)->toDateString(),
                    'hours' => (float) $a->hours,
                    'is_holiday_work' => $a->is_holiday_work,
                    'compensatory_days' => (float) $a->compensatory_days,
                    'reason' => $a->reason,
                    'status' => $a->approvalRequest?->status->value ?? 'pending',
                    'is_applied' => $a->isApplied(),
                    'cancelled' => $cancelled($a),
                    'actions' => $inputPolicy->actions($a, $request->user()),
                ]),
            'bonus_events' => BonusEvent::with(['period', 'awards'])
                ->when($periodId, fn ($q) => $q->where('payroll_period_id', $periodId))
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn (BonusEvent $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'basis' => $e->calculation_basis->value,
                    'basis_label' => $e->calculation_basis->label(),
                    'value' => (float) $e->value,
                    'period' => $e->period?->label(),
                    'note' => $e->note,
                    'award_count' => $e->awards->count(),
                    'award_total' => round((float) $e->awards->sum('amount'), 2),
                ]),
            'bonus_awards' => BonusAward::with(['employee:id,employee_code,first_name,last_name', 'event:id,name,payroll_period_id', 'event.period', 'approvalRequest'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (BonusAward $a) => [
                    'id' => $a->id,
                    'employee' => $a->employee?->fullName(),
                    'event' => $a->event?->name,
                    'amount' => (float) $a->amount,
                    'basis_note' => $a->basis_note,
                    'status' => $a->approvalRequest?->status->value ?? 'pending',
                    'cancelled' => $cancelled($a),
                    'actions' => $inputPolicy->actions($a, $request->user()),
                ]),
            'correctionReasons' => CorrectionReasons::for(['cancellation']),
            'adjustments' => PayrollAdjustment::with(['employee:id,employee_code,first_name,last_name', 'period', 'approvalRequest', 'attachments', 'cancelOffset.period'])
                ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
                ->when($periodId, fn ($q) => $q->where('payroll_period_id', $periodId))
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (PayrollAdjustment $a) => [
                    'id' => $a->id,
                    'employee' => $a->employee?->fullName(),
                    'type' => $a->type->value,
                    'type_label' => $a->type->label(),
                    'is_earning' => $a->type->isEarning(),
                    'amount' => (float) $a->amount,
                    'period' => $a->period?->label(),
                    'reason' => $a->reason,
                    // A generated arrear has no approval of its own (SAL-05).
                    'status' => $a->approvalRequest?->status->value ?? ($a->source_key ? 'generated' : 'pending'),
                    'is_generated' => $a->source_key !== null && $a->approvalRequest === null,
                    'evidence_id' => $a->attachments->first()?->id,
                    // ⭐ Corrections wave 2 (24 Oct 2026): Cancel, and its mark.
                    'cancelled' => $a->isCancelled() ? [
                        'at' => $a->cancelled_at?->toDateString(),
                        'reason' => $a->cancel_reason,
                        'offset' => $a->cancelOffset === null ? null : "#{$a->cancelOffset->id} {$a->cancelOffset->type->label()}, {$a->cancelOffset->period?->label()}",
                    ] : null,
                    'actions' => $adjustmentPolicy->actionsFor($a, $request->user())->toArray()['actions'],
                ]),
            'balances' => $employeeId
                ? $ledger->entriesFor($employeeId)->map(fn (PayrollBalanceEntry $e) => [
                    'id' => $e->id,
                    'date' => Carbon::parse($e->occurred_on)->toDateString(),
                    'entry_type' => $e->entry_type->label(),
                    'amount' => (float) $e->amount,
                    'period' => $e->period?->label(),
                    'note' => $e->note,
                ])
                : collect(),
            'outstanding' => $employeeId ? $ledger->outstandingFor($employeeId) : 0.0,
            // PAY-11: the blocked payslips a human still has to decide about.
            'negative_payslips' => $this->negativePayslips(),
            'employees' => $this->employeeOptions(),
            'periods' => PayrollPeriod::orderByDesc('start_date')->limit(24)->get()
                ->map(fn (PayrollPeriod $p) => [
                    'id' => $p->id,
                    'label' => $p->label(),
                    'is_open' => ($p->latestRun()?->status->isOpen()) ?? true,
                ]),
            'adjustment_types' => collect(PayrollAdjustmentType::cases())
                ->reject(fn (PayrollAdjustmentType $t) => $t->isSystemGenerated())
                ->map(fn (PayrollAdjustmentType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'is_earning' => $t->isEarning(),
                    'describe' => $t->describe(),
                ])->values(),
            // ⚠ Filtered, not the whole enum. `SalaryCalculationBasis` is shared
            // with the salary structure, so C14's per-day allowance basis
            // reaches this list unless it is excluded here — and it has no
            // meaning for a one-off award (see `BonusEvent::amountFor`).
            'bonus_bases' => collect(SalaryCalculationBasis::cases())
                ->filter(fn (SalaryCalculationBasis $b) => $b->allowedForBonus())
                ->map(fn (SalaryCalculationBasis $b) => ['value' => $b->value, 'label' => $b->label()])
                ->values(),
            'overtime_rules' => [
                'daily_max_hours' => PayrollConfig::defaults()->overtimeDailyMaxHours,
                'monthly_max_hours' => PayrollConfig::defaults()->overtimeMonthlyMaxHours,
                'minimum_minutes' => PayrollConfig::defaults()->overtimeMinimumMinutes,
                'standard_hours' => PayrollConfig::defaults()->standardWorkingHours,
            ],
        ]);
    }

    // --- Overtime (ADJ-01..04) -------------------------------------------------

    public function storeOvertime(Request $request, RequestOvertime $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            'work_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'is_holiday_work' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->handle(
            Employee::findOrFail($validated['employee_id']),
            $validated['work_date'],
            (float) $validated['hours'],
            (bool) $validated['is_holiday_work'],
            $validated['reason'],
            $request->user(),
        );

        return back()->with('success', 'Overtime assignment raised for approval.');
    }

    public function approveOvertime(
        Request $request,
        OvertimeAssignment $overtime,
        ApplyOvertimeAssignment $apply,
        NotifyStageApprovers $notify,
    ): RedirectResponse {
        $approval = $this->guardApproval($request, $overtime);
        $note = $this->note($request);

        // ⚠ Re-checked at approval: the month may have been reviewed since the
        // overtime was raised, and approved hours there would never be paid.
        if (! $overtime->is_holiday_work) {
            PayrollInputWindow::assertAccepts(PayrollInputWindow::periodContaining($overtime->work_date), 'work_date');
        }

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), $overtime->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $applied = $apply->handle($overtime);

        return back()->with('success', $applied->is_holiday_work
            ? sprintf('Approved. %s day(s) of compensatory leave credited (ADJ-04).', rtrim(rtrim(number_format((float) $applied->compensatory_days, 2), '0'), '.'))
            : 'Overtime approved. It will be priced on the next payroll run.');
    }

    public function rejectOvertime(Request $request, OvertimeAssignment $overtime): RedirectResponse
    {
        $this->guardApproval($request, $overtime)->reject($request->user(), $this->note($request));

        return back()->with('success', 'Overtime rejected.');
    }

    // --- Bonus (ADJ-05) --------------------------------------------------------

    public function storeBonusEvent(Request $request): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'calculation_basis' => ['required', new EnumRule(SalaryCalculationBasis::class)],
            'value' => ['required', 'numeric', 'min:0.0001'],
            'payroll_period_id' => ['required', Rule::exists('payroll_periods', 'id')->where('business_id', $this->businessId())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // The form already omits it, but a hand-posted request must not be able
        // to store a basis `BonusEvent` cannot price (C14).
        if (! SalaryCalculationBasis::from($validated['calculation_basis'])->allowedForBonus()) {
            throw ValidationException::withMessages([
                'calculation_basis' => 'A bonus cannot be calculated per day reported for work. That basis belongs to a standing salary component.',
            ]);
        }

        BonusEvent::create([...$validated, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Bonus event created. Award it to employees next.');
    }

    public function awardBonus(Request $request, BonusEvent $event, AwardBonus $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
        ]);

        $result = $action->handle(
            $event,
            Employee::whereIn('id', $validated['employee_ids'])->get(),
            $request->user(),
        );

        return back()->with('success', sprintf(
            '%d award(s) raised totalling %s. %d skipped (already awarded, or no salary structure).',
            $result['awarded'], number_format($result['total'], 2), $result['skipped'],
        ));
    }

    public function approveBonus(Request $request, BonusAward $award, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $this->guardApproval($request, $award);
        PayrollInputWindow::assertAccepts($award->event?->period);

        if (! $approval->approve($request->user(), $this->note($request))) {
            $notify->handle($approval->fresh(), $award->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        return back()->with('success', 'Bonus approved. It will appear on the payslip for that month.');
    }

    public function rejectBonus(Request $request, BonusAward $award): RedirectResponse
    {
        $this->guardApproval($request, $award)->reject($request->user(), $this->note($request));

        return back()->with('success', 'Bonus rejected.');
    }

    // --- Adjustments (ADJ-08/09) ----------------------------------------------

    public function storeAdjustment(Request $request, RequestPayrollAdjustment $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            'type' => ['required', new EnumRule(PayrollAdjustmentType::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payroll_period_id' => ['required', Rule::exists('payroll_periods', 'id')->where('business_id', $this->businessId())],
            'reason' => ['required', 'string', 'max:1000'],
            // ADJ-09 makes this mandatory, so it is required here and not merely
            // accepted if offered.
            'evidence' => ['required', 'file', 'max:10240'],
        ]);

        $action->handle(
            Employee::findOrFail($validated['employee_id']),
            PayrollAdjustmentType::from($validated['type']),
            (float) $validated['amount'],
            PayrollPeriod::findOrFail($validated['payroll_period_id']),
            $validated['reason'],
            $request->file('evidence'),
            $request->user(),
        );

        return back()->with('success', 'Adjustment raised for approval.');
    }

    public function approveAdjustment(Request $request, PayrollAdjustment $adjustment, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $this->guardApproval($request, $adjustment);
        PayrollInputWindow::assertAccepts($adjustment->period);

        if (! $approval->approve($request->user(), $this->note($request))) {
            $notify->handle($approval->fresh(), $adjustment->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        return back()->with('success', 'Adjustment approved. It will appear on the payslip for that month.');
    }

    public function rejectAdjustment(Request $request, PayrollAdjustment $adjustment): RedirectResponse
    {
        $this->guardApproval($request, $adjustment)->reject($request->user(), $this->note($request));

        return back()->with('success', 'Adjustment rejected.');
    }

    // --- PAY-11 carry-forward --------------------------------------------------

    /**
     * PAY-11's manual resolution. Recalculate the run afterwards and the credit
     * squares the payslip; the balance is then recovered from the next month.
     */
    public function carryForward(Request $request, Payslip $payslip, CarryForwardNegativeNet $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $entry = $action->handle($payslip, $note);

        return back()->with('success', sprintf(
            'Shortfall of %s carried forward. Recalculate the payroll to clear the block; the balance is recovered from the next month.',
            number_format((float) $entry->amount, 2),
        ));
    }

    // --- Shared ----------------------------------------------------------------

    /**
     * PAY-11's blocked lines, gathered from every run still open enough to act
     * on. A payslip whose shortfall has already been carried forward drops off
     * the list, so what remains is exactly what still needs a decision.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function negativePayslips()
    {
        return Payslip::with(['employee:id,employee_code,first_name,last_name', 'run.period'])
            ->where('net', '<', 0)
            ->get()
            ->filter(fn (Payslip $p) => $p->run?->status->isRerunnable() ?? false)
            ->filter(fn (Payslip $p) => ! PayrollBalanceEntry::withoutGlobalScopes()
                ->where('source_key', PayrollBalanceLedger::carryForwardKey($p->employee_id, $p->run->payroll_period_id))
                ->exists())
            ->map(fn (Payslip $p) => [
                'id' => $p->id,
                'employee' => $p->employee?->fullName(),
                'period' => $p->run?->period?->label(),
                'net' => (float) $p->net,
                'block_reasons' => $p->block_reasons ?? [],
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function employeeOptions()
    {
        return Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
            ->orderBy('employee_code')->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->fullName().' ('.$e->employee_code.')',
                // ADJ-01 / C8d, surfaced so the form can explain a refusal before
                // it happens rather than after.
                'overtime_eligible' => (bool) (SalaryStructure::activeFor($e->id)?->overtime_eligible ?? false),
            ]);
    }

    private function guardApproval(Request $request, $model): ApprovalRequest
    {
        $approval = $model->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        return $approval;
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
    }
}
