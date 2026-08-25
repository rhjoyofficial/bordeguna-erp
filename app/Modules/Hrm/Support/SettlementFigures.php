<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Support\Facades\DB;

/**
 * What a leaver is owed and what they owe, derived once.
 *
 * ⚠⚠ ONE DEFINITION, TWO READERS. The settlement worksheet report and the
 * settlement statement both answer "what is outstanding for this person", and
 * the report's own comment already warned that re-deriving these any other way
 * "would create a second opinion about a leaver's money". Two opinions about a
 * final payment is the worst possible place for a rounding difference, so the
 * SQL lives here and both callers use it.
 *
 * ⚠ Every figure is a sum over a ledger, never a stored total: the loan
 * balance, the leave balance and what has actually been paid are all sums by
 * construction elsewhere in the system.
 */
class SettlementFigures
{
    /**
     * Net still unpaid across every payslip: the slip's net less what payment
     * events have settled, counting a reversal as money that came back.
     */
    public static function unpaidSalarySql(string $employeeColumn = 'employees.id'): string
    {
        $settled = '(select coalesce(sum(case payslip_payments.event_type'
            ." when 'paid' then payslip_payments.amount"
            ." when 'reversed' then -payslip_payments.amount"
            .' else 0 end), 0) from payslip_payments'
            .' where payslip_payments.payslip_id = payslips.id)';

        return '(select coalesce(sum(payslips.net - '.$settled.'), 0)'
            .' from payslips where payslips.employee_id = '.$employeeColumn
            .' and payslips.net > '.$settled.')';
    }

    public static function loanOutstandingSql(string $employeeColumn = 'employees.id'): string
    {
        return '(select coalesce(sum(loan_ledger_entries.amount), 0)'
            .' from loan_ledger_entries'
            .' where loan_ledger_entries.employee_id = '.$employeeColumn.')';
    }

    /**
     * C6a: only leave types marked encashable count, in DAYS.
     *
     * ⚠ A negative balance is not a debt to the employee, so it is clamped at
     * zero. ⚠ A CASE rather than GREATEST/MAX: MySQL's MAX is an aggregate and
     * would not take two arguments while SQLite's is scalar and would, and this
     * report runs on MySQL in production.
     */
    public static function encashableLeaveDaysSql(string $employeeColumn = 'employees.id'): string
    {
        return '(select case when coalesce(sum(leave_ledger_entries.days), 0) < 0'
            .' then 0 else coalesce(sum(leave_ledger_entries.days), 0) end'
            .' from leave_ledger_entries'
            .' join leave_types on leave_types.id = leave_ledger_entries.leave_type_id'
            .' where leave_ledger_entries.employee_id = '.$employeeColumn
            .' and leave_types.can_encash = 1)';
    }

    /**
     * The same three figures for one person.
     *
     * @return array{unpaid_salary: float, loan_outstanding: float, encashable_leave_days: float}
     */
    public static function for(Employee $employee): array
    {
        $row = DB::query()
            ->selectRaw(self::unpaidSalarySql((string) $employee->id).' as unpaid_salary')
            ->selectRaw(self::loanOutstandingSql((string) $employee->id).' as loan_outstanding')
            ->selectRaw(self::encashableLeaveDaysSql((string) $employee->id).' as encashable_leave_days')
            ->first();

        return [
            'unpaid_salary' => round((float) ($row->unpaid_salary ?? 0), 2),
            'loan_outstanding' => round((float) ($row->loan_outstanding ?? 0), 2),
            'encashable_leave_days' => round((float) ($row->encashable_leave_days ?? 0), 2),
        ];
    }

    /**
     * Whether any leave in this business is encashable at all.
     *
     * ⚠⚠ THE STATEMENT'S SAFETY CATCH. Every leave type is currently marked not
     * encashable, which is why a settlement can be totalled at all: there is no
     * unpriced component left in it. The moment somebody ticks a type as
     * encashable that stops being true, the encashment RATE becomes a rule
     * nobody has written down (EMP-08 / C10b), and a total containing an
     * invented price would be paid to a person. So the statement asks this
     * question every time and refuses to total when the answer changes, rather
     * than trusting a policy that was true on the day it was written.
     */
    public static function anyLeaveIsEncashable(): bool
    {
        return LeaveType::query()->where('can_encash', true)->exists();
    }
}
