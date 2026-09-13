<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\DisburseLoan;
use App\Modules\Hrm\Actions\RequestLoan;
use App\Modules\Hrm\Actions\SettleLoan;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\LoanType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanInstallment;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Support\LoanSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\ValidationException;
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
                ->orderBy('employee_code')->get()
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->fullName().' ('.$e->employee_code.')',
                    'outstanding' => LoanLedgerEntry::outstandingForEmployee($e->id),
                ]),
            'types' => collect(LoanType::cases())
                ->map(fn (LoanType $t) => ['value' => $t->value, 'label' => $t->label()]),
            // Approving disburses, so the screen has to be able to ask where the
            // money came from at the moment it asks whether to lend it.
            'moneyAccounts' => MoneyAccount::query()->handled()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name]),
            'recoverableFrom' => $this->earliestRecoverableMonth(),
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

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
            // ⚠ The day the money actually left, which is often not today.
            'disbursed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $note = $data['note'] ?? null;

        // ⚠⚠ ASKED BEFORE THE APPROVAL IS RECORDED, NEVER AFTER. This screen
        // used to post nothing at all, so the final approval went through and
        // the disbursement then threw "the account this loan was paid from" -
        // leaving an approved loan that had never been paid out, and a 500 in
        // place of a question. The account is a fact only the person handing
        // the money over knows, so it is asked, not defaulted.
        $isFinalStage = $approval->remainingStages() <= 1;
        $account = isset($data['money_account_id']) ? MoneyAccount::find($data['money_account_id']) : null;

        if ($isFinalStage && $account === null) {
            throw ValidationException::withMessages([
                'money_account_id' => 'Say which account this money was paid from. Approving disburses it, and the ledger cannot record cash leaving an account nobody named.',
            ]);
        }

        if (! $approval->approve($request->user(), $note)) {
            $notify->handle($approval->fresh(), $loan->summaryLine());

            return back()->with('success', 'Approved. Sent to the next stage.');
        }

        $applied = $disburse->handle($loan, $account, $request->user(), $data['disbursed_on'] ?? null);

        return back()->with('success', sprintf(
            '%s disbursed from %s on %s. %d installment(s) scheduled from %s.',
            $applied->type->label(),
            $account?->name ?? 'no account',
            $applied->disbursed_on?->format('j M Y') ?? 'today',
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

    /**
     * The earliest payroll month a repayment could still come out of.
     *
     * ⚠⚠ NOT "this calendar month". On 1 September 2026 three advances handed
     * out during August had to be recovered from the August payroll, which was
     * approved but unpaid. Defaulting the form to September would have set the
     * schedule a month late and nobody would have noticed until the money did
     * not come off. The oldest month still unpaid is the honest default; if
     * every month is paid, next month's salary is the first one that can carry
     * it.
     *
     * @return array{year: int, month: int, label: string}
     */
    private function earliestRecoverableMonth(): array
    {
        $open = PayrollPeriod::orderBy('year')->orderBy('month')->get()
            ->first(fn (PayrollPeriod $p) => $p->latestRun()?->status?->isPaidOut() !== true);

        $fallback = Carbon::today()->startOfMonth();

        return [
            'year' => (int) ($open?->year ?? $fallback->year),
            'month' => (int) ($open?->month ?? $fallback->month),
            'label' => $open?->label() ?? $fallback->format('F Y'),
        ];
    }

    private function businessId(): int
    {
        return app(BusinessContext::class)->id();
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
    public function preview(Request $request): JsonResponse
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
