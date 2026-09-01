<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The salary register: what this business paid in wages, month by month, for
 * as long as it has been running payroll.
 *
 * Joy, 1 Sep 2026: "each month how much salary we are paying, this must have
 * in record, so after 1 year or 10 years i can see."
 *
 * ⚠⚠ **The payroll screen answers a different question and cannot answer this
 * one.** It shows ONE month, chosen by a year/month filter that defaults to
 * today, and its quick-jump list is capped at twelve periods - so the
 * thirteenth month back was already unreachable without typing, and no screen
 * anywhere added the months up. The data was never at risk: periods, runs and
 * payslips are all kept. Only the reading of them was missing.
 *
 * ⚠ **It reads finished runs and recomputes nothing** - the same rule the
 * salary sheet and the accounting export follow. A register that re-derived
 * pay from today's salary structures would report what those months WOULD cost
 * now, which is a different number from what was paid, and the difference
 * grows with every increment.
 *
 * ⚠ **A month is its LATEST version (WFL-06).** A reopened payroll produces v2
 * and supersedes v1; adding both would double-count the month. The version is
 * shown so a reopened month is visible as one.
 *
 * ⚠⚠ **"Paid" means Disbursed or Locked** (PayrollStatus::isPaidOut), and the
 * yearly totals keep it apart from everything else. A calculated-but-unapproved
 * month is a plan, not a payment, and quietly adding it to a ten-year wage
 * total would overstate what the business actually spent.
 */
class PayrollHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('hrm.payroll.manage');

        $employeeId = $request->integer('employee_id') ?: null;
        $employee = $employeeId === null ? null : Employee::find($employeeId);

        $rows = $employee === null
            ? $this->businessRows()
            : $this->employeeRows($employee->id);

        return Inertia::render('hrm/payroll/history', [
            'filters' => ['employee_id' => $employee?->id],
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
            ],
            'years' => $this->groupIntoYears($rows),
            'grand_total_paid' => round((float) $rows->where('is_paid', true)->sum('net'), 2),
            'months_paid' => $rows->where('is_paid', true)->count(),
            'employees' => Employee::whereHas('assignments')
                ->orderBy('employee_code')->get()
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullName().' ('.$e->employee_code.')']),
        ]);
    }

    /**
     * One row per payroll month for the whole business.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function businessRows(): Collection
    {
        return $this->latestRuns()->map(fn (PayrollRun $run) => [
            'period_id' => $run->payroll_period_id,
            'year' => $run->period?->year,
            'month' => $run->period?->month,
            'label' => $run->period?->label(),
            'cycle' => $run->period === null
                ? null
                : $run->period->start_date->toDateString().' to '.$run->period->end_date->toDateString(),
            'version' => $run->version,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'is_paid' => $run->status->isPaidOut(),
            'employee_count' => $run->employee_count,
            'gross' => null,
            'earnings' => (float) $run->total_earnings,
            'deductions' => (float) $run->total_deductions,
            'net' => (float) $run->total_net,
            'paid_on' => $run->disbursed_at?->format('Y-m-d'),
            'url' => route('hrm.payroll.index', ['year' => $run->period?->year, 'month' => $run->period?->month]),
        ])->values();
    }

    /**
     * One row per payroll month for ONE person - their own wage record.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function employeeRows(int $employeeId): Collection
    {
        $runs = $this->latestRuns()->keyBy('id');

        return Payslip::query()
            ->whereIn('payroll_run_id', $runs->keys())
            ->where('employee_id', $employeeId)
            ->get()
            ->map(function (Payslip $payslip) use ($runs) {
                $run = $runs[$payslip->payroll_run_id];

                return [
                    'period_id' => $run->payroll_period_id,
                    'year' => $run->period?->year,
                    'month' => $run->period?->month,
                    'label' => $run->period?->label(),
                    'cycle' => $run->period === null
                        ? null
                        : $run->period->start_date->toDateString().' to '.$run->period->end_date->toDateString(),
                    'version' => $run->version,
                    'status' => $run->status->value,
                    'status_label' => $run->status->label(),
                    'is_paid' => $run->status->isPaidOut(),
                    'employee_count' => null,
                    'gross' => (float) $payslip->gross_salary,
                    'earnings' => (float) $payslip->total_earnings,
                    'deductions' => (float) $payslip->total_deductions,
                    'net' => (float) $payslip->net,
                    'paid_on' => $run->disbursed_at?->format('Y-m-d'),
                    'url' => route('hrm.payslips.show', $payslip->id),
                ];
            })
            ->sortByDesc(fn (array $row) => sprintf('%04d-%02d', $row['year'] ?? 0, $row['month'] ?? 0))
            ->values();
    }

    /**
     * The surviving version of every payroll month, newest month first.
     *
     * @return Collection<int, PayrollRun>
     */
    private function latestRuns(): Collection
    {
        return PayrollRun::with('period')
            ->get()
            ->groupBy('payroll_period_id')
            ->map(fn (Collection $versions) => $versions->sortByDesc('version')->first())
            ->filter(fn (?PayrollRun $run) => $run?->period !== null)
            ->sortByDesc(fn (PayrollRun $run) => sprintf('%04d-%02d', $run->period->year, $run->period->month))
            ->values();
    }

    /**
     * Rows grouped into calendar years, each with the totals a year-end reading
     * actually wants: what was PAID, and what is merely calculated.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupIntoYears(Collection $rows): array
    {
        return $rows->groupBy('year')
            ->map(fn (Collection $months, $year) => [
                'year' => (int) $year,
                'months' => $months->values()->all(),
                'net_paid' => round((float) $months->where('is_paid', true)->sum('net'), 2),
                'net_unpaid' => round((float) $months->where('is_paid', false)->sum('net'), 2),
                'months_paid' => $months->where('is_paid', true)->count(),
            ])
            ->sortByDesc('year')
            ->values()
            ->all();
    }
}
