<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\EmployeeStatement;
use App\Modules\Accounting\Support\FiscalYear;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One employee's ledgers: advances, salary owed, deductions held, claims owed.
 *
 * The accountant's "a ledger head for every employee" (26 Sep 2026), answered
 * without a chart row per person: the control heads are the ledgers and the
 * employee on every line is the sub-ledger (Support\PartyLedgers). This is the
 * screen he opens to see Musa Islam's ledger, per head, with a running balance.
 *
 * ⚠ `accounting.view`, like the customer statement. Salary owed per person is
 * payroll-grade data, and accounting.view was scoped for exactly that reason.
 */
class EmployeeStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $business = Business::withoutGlobalScopes()->find(app(BusinessContext::class)->id());
        [$from, $to] = $this->window($request, $business);

        // Every employee, separated ones included: a person who left may still
        // be owed, or owe, and "nothing outstanding" is an answer too.
        $employees = Employee::query()->orderBy('first_name')->orderBy('last_name')->get()->map(fn (Employee $option) => [
            'id' => $option->id,
            'label' => PartyLedgers::label($option).' ('.$option->employee_code.')',
            'is_active' => $option->status !== EmployeeStatus::Separated,
        ]);

        $employee = $request->filled('employee') ? Employee::query()->find($request->integer('employee')) : null;

        if ($employee === null) {
            return Inertia::render('accounting/employee-statement/index', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employees' => $employees,
                'employee' => null,
                'heads' => [],
            ]);
        }

        $statement = EmployeeStatement::for($employee, $from, $to);

        return Inertia::render('accounting/employee-statement/index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'employees' => $employees,
            'employee' => [
                'id' => $employee->id,
                'name' => PartyLedgers::label($employee),
                'code' => $employee->employee_code,
            ],
            'heads' => $statement->heads->map(fn (array $head) => [
                'account' => $head['account']->label(),
                'code' => $head['account']->code,
                'slug' => $head['account']->system_slug,
                'normal_balance' => $head['account']->normalBalance()->value,
                'opening' => $head['opening']->absolute()->format(),
                'opening_side' => $head['opening']->isCredit() ? 'credit' : 'debit',
                'debits' => $head['debits']->format(),
                'credits' => $head['credits']->format(),
                'closing' => $head['closing']->absolute()->format(),
                'closing_side' => $head['closing']->isCredit() ? 'credit' : 'debit',
                'lines' => $head['lines']->map(fn (array $line) => [
                    'date' => $line['date'],
                    'journal_number' => $line['journal_number'],
                    'journal_id' => $line['journal_id'],
                    'memo' => $line['memo'],
                    'source' => $line['source'],
                    'debit' => $line['amount']->isDebit() ? $line['amount']->format() : null,
                    'credit' => $line['amount']->isDebit() ? null : $line['amount']->absolute()->format(),
                    'balance' => $line['balance']->absolute()->format(),
                    'balance_side' => $line['balance']->isCredit() ? 'credit' : 'debit',
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Fiscal year to date by default, like every accounting report.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request, ?Business $business): array
    {
        $today = Carbon::today();
        $yearStart = $business === null ? $today->copy()->startOfYear() : FiscalYear::containing($today, $business)->startsOn();

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $yearStart;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
