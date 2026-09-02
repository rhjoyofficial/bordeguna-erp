<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Reporting\Concerns\SignsFromPayrollRun;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Concerns\ResolvesAssignments;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * RPT-02 #11 — Earnings and deductions breakdown.
 *
 * The salary sheet opened up: one row per employee, one column per component
 * that actually appears in the run. This is the report #8 deliberately is not —
 * a salary sheet that expanded every component would be a hundred columns wide
 * and would stop being a salary sheet.
 *
 * **The second report whose columns depend on its parameters**, and for a
 * different reason from the register's. The register's columns are decided by
 * the calendar; these are decided by the *data* — the components in force in
 * this business, in this run. A fixed column list would be wrong for any
 * business that added a component, which SAL-02/03 explicitly allows, and wrong
 * silently: the column would simply not appear and its money would vanish from a
 * report whose totals still footed.
 *
 * ⚠ **A component present on nobody's payslip gets no column.** The columns are
 * the components *in this run*, not the components on file. Printing an empty
 * column for every allowance the business has ever defined would bury the six
 * that matter.
 *
 * The earning/deduction split is not re-derived here: it is carried by the
 * component's own type, which SAL-02/03 decides and `PayslipLine` stores, and
 * the two totals columns come straight off the payslip for the same reason.
 *
 * ⚠ **The row is assembled from an ordered stream, one employee at a time** —
 * the same technique as the monthly register, for the same reason. Pivoting in
 * PHP with everything loaded would undo C10a.
 */
class EarningsDeductionsBreakdownReport implements ReportDefinition
{
    use SignsFromPayrollRun;

    use CountsRows, ResolvesAssignments;

    public function key(): string
    {
        return 'payroll_component_breakdown';
    }

    public function name(): string
    {
        return 'Earnings and deductions breakdown';
    }

    public function description(): string
    {
        return 'Every employee on a payroll run with each earning and deduction in its own column — the salary sheet opened up component by component.';
    }

    public function group(): string
    {
        return 'Payroll';
    }

    public function permission(): string
    {
        return 'hrm.payroll.manage';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::payrollRun(),
            ReportParameter::office(),
            ReportParameter::department(),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        $columns = [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
        ];

        foreach ($this->components($parameters) as $component) {
            $columns[] = ReportColumn::money('c_'.$component->code, $component->label);
        }

        return [
            ...$columns,
            ReportColumn::money('total_earnings', 'Total earnings'),
            ReportColumn::money('total_deductions', 'Total deductions'),
            ReportColumn::money('net', 'Net payable'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        $keys = ['total_earnings', 'total_deductions', 'net'];

        foreach ($this->components($parameters) as $component) {
            $keys[] = 'c_'.$component->code;
        }

        return $keys;
    }

    /**
     * The components this run actually used, in payslip order.
     *
     * Ordered by the sequence the payslip itself uses, so the columns read left
     * to right the way a payslip reads top to bottom — Basic first, then the
     * other earnings, then the deductions. A report whose columns are in a
     * different order from the document they summarise is read wrongly by
     * everybody once.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function components(?ReportParameters $parameters)
    {
        $runId = $parameters?->int('run_id');

        if ($runId === null) {
            return collect();
        }

        return PayslipLine::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->where('payslips.payroll_run_id', $runId)
            ->groupBy('payslip_lines.code', 'payslip_lines.label', 'payslip_lines.type')
            ->orderByRaw('min(payslip_lines.sequence)')
            ->get([
                'payslip_lines.code',
                'payslip_lines.label',
                'payslip_lines.type',
            ]);
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $blank = [];

            foreach ($this->components($parameters) as $component) {
                // Zero rather than blank: a spreadsheet column of amounts with
                // gaps in it does not sum, and "this employee did not receive
                // this allowance" is genuinely zero.
                $blank['c_'.$component->code] = 0;
            }

            $current = null;

            foreach ($this->query($parameters)->toBase()->cursor() as $line) {
                if ($current !== null && $current['payslip_id'] !== $line->payslip_id) {
                    yield $this->emit($current);
                    $current = null;
                }

                $current ??= [
                    'payslip_id' => $line->payslip_id,
                    'employee_code' => $line->employee_code,
                    'employee_name' => trim($line->first_name.' '.$line->last_name),
                    'department' => $line->department_name,
                    ...$blank,
                    'total_earnings' => $line->total_earnings,
                    'total_deductions' => $line->total_deductions,
                    'net' => $line->net,
                ];

                $key = 'c_'.$line->code;

                // A component the column list does not know about is ignored
                // rather than added on the fly: the columns were resolved once
                // by the runner, and a row with an extra key would be silently
                // dropped by every writer.
                if (array_key_exists($key, $blank)) {
                    // += rather than =, because a payslip may legitimately carry
                    // the same component twice — a loan installment and an
                    // arrear both post under their own code, and PAY-13's
                    // quantity/rate lines are per occurrence.
                    $current[$key] = round((float) $current[$key] + (float) $line->amount, 2);
                }
            }

            if ($current !== null) {
                yield $this->emit($current);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function emit(array $row): array
    {
        unset($row['payslip_id']);

        return $row;
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Rows are payslips, not lines.
        return $this->query($parameters)
            ->toBase()
            ->distinct()
            ->count('payslips.id');
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $run = $this->run($parameters);

        $signs = $this->signatories($run);

        return new ReportMeta(
            title: 'Earnings and deductions breakdown',
            subtitle: $run === null
                ? null
                : sprintf('%s — run v%d (%s)', (string) $run->period?->label(), $run->version, $run->status->label()),
            periodLocked: $run?->status?->isFinal() ?? false,
            lockNote: 'Columns are the components this run actually used, in payslip order.',
            preparedBy: $signs['preparedBy'],
            checkedBy: $signs['checkedBy'],
            approvedBy: $signs['approvedBy'],
        );
    }

    private function run(ReportParameters $parameters): ?PayrollRun
    {
        $id = $parameters->int('run_id');

        return $id === null ? null : PayrollRun::with('period')->find($id);
    }

    private function query(ReportParameters $parameters): Builder
    {
        $run = $this->run($parameters);
        $asAt = ($run?->period?->end_date ?? now())->toDateString();

        $query = PayslipLine::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id');

        $query = $this->joinAssignmentAsAt($query, 'payslips.employee_id', $asAt);
        $query = $this->filterByAssignment($query, $parameters);

        return $query
            ->when(
                $parameters->int('run_id'),
                fn (Builder $q, int $id) => $q->where('payslips.payroll_run_id', $id),
            )
            // ⚠ Employee first, then sequence. The pivot depends on one
            // payslip's lines arriving together; any other order produces one
            // row per line, which still renders and is still wrong.
            ->orderBy('employees.employee_code')
            ->orderBy('payslip_lines.sequence')
            ->select([
                'payslip_lines.payslip_id',
                'payslip_lines.code',
                'payslip_lines.amount',
                'payslips.total_earnings',
                'payslips.total_deductions',
                'payslips.net',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                ...$this->assignmentColumns(),
            ]);
    }

}
