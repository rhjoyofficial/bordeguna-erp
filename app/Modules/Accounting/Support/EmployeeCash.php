<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Initial plan B3 (R60 to R63, Joy's Q1 = A, 25 Oct 2026): "Treat Employees
 * as Bank Accounts". Company cash an employee holds is an Employee cash money
 * account ("Petty Cash - Sifat"): funded by a transfer, spent on purchases and
 * expenses from the ordinary payment screens, and allowed below zero.
 *
 *   balance above zero   he holds that much of the company's cash
 *   balance below zero   he paid more than he held: the company owes him
 *
 * ⚠ The balance is READ from the ledger head (MoneyAvailability), never
 * stored, like every other money account. Paying him back is a transfer INTO
 * his account; taking cash back from him is a transfer OUT of it. No expense
 * claim is needed for what he paid from this account (expense claims stay for
 * other staff costs).
 */
final class EmployeeCash
{
    /**
     * How the balance reads: "holds Tk 4,000.00" or "company owes Tk 10,000.00".
     *
     * @return array{position?: 'holds'|'owed'|'even', position_amount?: string}
     */
    public static function position(MoneyAccount $account, ?Money $balance = null): array
    {
        if (! $account->isEmployeeCash()) {
            return [];
        }

        $balance ??= Money::ofMinor(MoneyAvailability::balanceMinor($account));

        return [
            'position' => match (true) {
                $balance->minor > 0 => 'holds',
                $balance->minor < 0 => 'owed',
                default => 'even',
            },
            'position_amount' => Money::ofMinor(abs($balance->minor))->format(),
        ];
    }

    /**
     * Employees who may hold company cash (anyone who has not left), id => name.
     *
     * @return array<int, string>
     */
    public static function custodianNames(): array
    {
        return Employee::query()
            ->where('status', '!=', EmployeeStatus::Separated->value)
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->mapWithKeys(fn (Employee $employee) => [(int) $employee->id => trim($employee->fullName().($employee->employee_code ? " ({$employee->employee_code})" : ''))])
            ->all();
    }

    /**
     * R63: the employee's cash accounts in this business, each with what he
     * holds or is owed and its latest movements, for the employee page.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEmployee(Employee $employee, int $lines = 30): array
    {
        return MoneyAccount::query()
            ->where('type', MoneyAccountType::EmployeeCash->value)
            ->where('custodian_employee_id', $employee->id)
            ->orderBy('name')
            ->get()
            ->map(function (MoneyAccount $account) use ($lines) {
                $balance = Money::ofMinor(MoneyAvailability::balanceMinor($account));

                $movements = DB::table('journal_lines')
                    ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                    ->where('journal_lines.money_account_id', $account->id)
                    ->orderByDesc('journals.journal_date')->orderByDesc('journal_lines.id')
                    ->limit($lines)
                    ->get(['journals.id as journal_id', 'journals.journal_date', 'journals.memo', 'journal_lines.memo as line_memo', 'journal_lines.amount_minor']);

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_active' => (bool) $account->is_active,
                    'balance' => $balance->format(),
                    ...self::position($account, $balance),
                    'lines' => $movements->map(fn ($row) => [
                        'journal_id' => (int) $row->journal_id,
                        'date' => substr((string) $row->journal_date, 0, 10),
                        'memo' => $row->memo ?: $row->line_memo,
                        // + money came to him, - he paid it out.
                        'amount' => Money::ofMinor((int) $row->amount_minor)->format(),
                        'is_in' => (int) $row->amount_minor > 0,
                        'url' => route('accounting.journals.show', (int) $row->journal_id),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
