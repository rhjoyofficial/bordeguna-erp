<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Actions\DisburseLoan;
use App\Modules\Hrm\Actions\RequestLoan;
use App\Modules\Hrm\Actions\SettleLoan;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanInstallment;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Support\LoanSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loans and salary advances (ADJ-07, all options ticked).
 *
 * The screen shows all three layers at once, because a borrower's question is
 * never about only one of them: the agreement, the repayment plan, and the
 * ledger of what actually moved. The outstanding balance shown here is the sum
 * of that ledger — the same figure payroll deducts against, by construction.
 */
class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.payroll.manage');

        $employeeId = $request->integer('employee_id') ?: null;

        $loans = Loan::with([
            'employee:id,employee_code,first_name,last_name',
            'installments',
            'ledgerEntries.period',
            'approvalRequest',
            'requester:id,name',
        ])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderByDesc('id')->limit(100)->get();

        return Inertia::render('hrm/payroll/loans', [
            'filters' => ['employee_id' => $employeeId],
            'loans' => $loans->map(fn (Loan $loan) => $this->present($loan)),
            'employees' => Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
                ->orderBy('first_name')->get()
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->fullName().' ('.$e->employee_code.')',
                    'outstanding' => LoanLedgerEntry::outstandingForEmployee($e->id),
                ]),
            'types' => collect(LoanType::cases())
                ->map(fn (LoanType $t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    public function store(Request $request, RequestLoan $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employee_assignments', 'employee_id')->where('business_id', $this->businessId())],
            'type' => ['required', new EnumRule(LoanType::class)],
            'principal' => ['required', 'numeric', 'min:0.01'],
            'installment_amount' => ['required', 'numeric', 'min:0.01'],
            'start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $action->handle(
            Employee::findOrFail($validated['employee_id']),
            LoanType::from($validated['type']),
            (float) $validated['principal'],
            (float) $validated['installment_amount'],
            (int) $validated['start_year'],
            (int) $validated['start_month'],
            $validated['reason'],
            $request->user(),
        );

        return back()->with('success', 'Loan requested and sent for approval.');
    }

    /**
     * Approval disburses: the money goes out and the schedule comes into being.
     * Before this a loan owes nothing at all, which is the honest answer for
     * money that has not been handed over.
     */
    public function approve(Request $request, Loan $loan, DisburseLoan $disburse, NotifyStageApprovers $notify): RedirectResponse
    {
        $approval = $loan->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $note = $this->note($request);

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), $loan->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        // ⚠ The final approver says which account the money leaves from. Same
        // question a payroll disbursement asks, and for the same reason: where
        // the cash came from is a fact only the person handing it over knows.
        $account = $request->filled('money_account_id')
            ? MoneyAccount::find($request->integer('money_account_id'))
            : null;

        $applied = $disburse->handle($loan, $account, $request->user());

        return back()->with('success', sprintf(
            'Loan approved and disbursed. %d installment(s) scheduled from %s.',
            $applied->installments()->count(),
            $applied->startsOn()->format('F Y'),
        ));
    }

    public function reject(Request $request, Loan $loan): RedirectResponse
    {
        $approval = $loan->approvalRequest;
        abort_if($approval === null, 404);
        abort_unless($approval->canBeActionedBy($request->user()), 403);

        $approval->reject($request->user(), $this->note($request));
        $loan->update(['status' => LoanStatus::Rejected]);

        return back()->with('success', 'Loan rejected. Nothing was disbursed.');
    }

    /**
     * ADJ-07's early settlement. The amount received must clear the balance —
     * this is a debt paid early, not a debt forgiven.
     */
    public function settle(Request $request, Loan $loan, SettleLoan $action): RedirectResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'amount_received' => ['required', 'numeric', 'min:0.01'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $settled = $action->handle($loan, (float) $validated['amount_received'], $validated['note']);

        return back()->with('success', sprintf(
            '%s settled. %d remaining installment(s) cancelled.',
            $settled->reference,
            $settled->installments()->where('status', 'cancelled')->count(),
        ));
    }

    private function note(Request $request): ?string
    {
        return $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
    }

    private function businessId(): int
    {
        return app(\App\Modules\Foundation\Support\BusinessContext::class)->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Loan $loan): array
    {
        $outstanding = $loan->outstanding();

        return [
            'id' => $loan->id,
            'reference' => $loan->reference,
            'employee' => $loan->employee?->fullName(),
            'employee_code' => $loan->employee?->employee_code,
            'type' => $loan->type->value,
            'type_label' => $loan->type->label(),
            'status' => $loan->status->value,
            'status_label' => $loan->status->label(),
            'approval_status' => $loan->approvalRequest?->status->value ?? 'pending',
            'is_applied' => $loan->isApplied(),
            'can_settle' => $loan->status === LoanStatus::Active && $outstanding > 0,
            'principal' => (float) $loan->principal,
            'installment_amount' => (float) $loan->installment_amount,
            'installment_count' => (int) $loan->installment_count,
            'start' => $loan->startsOn()->format('F Y'),
            'reason' => $loan->reason,
            'disbursed_on' => $loan->disbursed_on?->toDateString(),
            'settlement_note' => $loan->settlement_note,
            // Never a stored column — the sum of the ledger below it.
            'outstanding' => $outstanding,
            'repaid' => $loan->totalRepaid(),
            'requested_by' => $loan->requester?->name,
            'installments' => $loan->installments->map(fn (LoanInstallment $i) => [
                'id' => $i->id,
                'sequence' => $i->sequence,
                'period' => $i->periodLabel(),
                'amount' => (float) $i->amount,
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'paid_at' => $i->paid_at?->format('Y-m-d'),
            ]),
            'ledger' => $loan->ledgerEntries->map(fn (LoanLedgerEntry $e) => [
                'id' => $e->id,
                'date' => Carbon::parse($e->occurred_on)->toDateString(),
                'entry_type' => $e->entry_type->label(),
                'increases_debt' => $e->entry_type->increasesDebt(),
                'amount' => (float) $e->amount,
                'period' => $e->period?->label(),
                'note' => $e->note,
            ]),
        ];
    }

    /**
     * What a set of terms would produce, for the form to show before anyone
     * commits to it.
     */
    public function preview(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('hrm.payroll.manage');

        $validated = $request->validate([
            'principal' => ['required', 'numeric', 'min:0.01'],
            'installment_amount' => ['required', 'numeric', 'min:0.01'],
            'start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $rows = LoanSchedule::build(
            (float) $validated['principal'],
            (float) $validated['installment_amount'],
            (int) $validated['start_year'],
            (int) $validated['start_month'],
        );

        return response()->json([
            'count' => count($rows),
            'total' => round(array_sum(array_column($rows, 'amount')), 2),
            'rows' => array_map(fn (array $r) => [
                ...$r,
                'period' => Carbon::create($r['year'], $r['month'], 1)->format('F Y'),
            ], $rows),
        ]);
    }
}
