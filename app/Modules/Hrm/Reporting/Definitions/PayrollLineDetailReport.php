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
 * The DIS-05 accounting detail, re-expressed as a report definition.
 *
 * Every line of every payslip in a run, with the office and department the cost
 * belongs to and the stored formula that produced the figure. This is the
 * auditable half of DIS-05: a summary nobody can drill into is not auditable.
 *
 * **Why it moved here.** The plan's reuse rule was that `AccountingExport` and
 * `GenericCsvFormat` are re-expressed on the framework "so there is exactly one
 * path from data to file". `AccountingExport` now produces its rows from this
 * definition, so the DIS-05 route and this report cannot disagree — and the
 * export gains xlsx, PDF and print for nothing, which RPT-03 asked for and
 * DIS-05's hand-rolled `fputcsv` could never have given it.
 *
 * ⚠⚠ **Moving it fixed a real defect.** The original built each row's office and
 * department from `$payslip->employee->currentAssignment()` — **today's**
 * assignment. An employee who transferred after payday had their whole month's
 * cost silently re-filed under their new department, changing an export of a
 * *closed* payroll every time it was re-run. The framework's rule is as at the
 * period end, and that is what this uses.
 *
 * ⚠ It also fixes an N+1 that the original had per payslip.
 */
class PayrollLineDetailReport implements ReportDefinition
{
    use SignsFromPayrollRun;

    use CountsRows, ResolvesAssignments;

    public function key(): string
    {
        return 'payroll_line_detail';
    }

    public function name(): string
    {
        return 'Payroll detail for accounting';
    }

    public function description(): string
    {
        return 'Every payslip line in a run with its office, department, quantity, rate and the formula that produced it — the auditable detail behind the summary.';
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
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('office', 'Office', 18),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('component_code', 'Component code', 16),
            ReportColumn::text('component', 'Component', 22),
            ReportColumn::text('type', 'Type', 12),
            ReportColumn::decimal('quantity', 'Quantity', 10),
            ReportColumn::money('rate', 'Rate'),
            ReportColumn::money('amount', 'Amount'),
            // PAY-13's stored formula travels with the figure, so an auditor
            // never has to ask the system what a number meant.
            ReportColumn::text('formula', 'Basis', 40),
            ReportColumn::text('source', 'Source', 20),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ Amount only. A total of rates is meaningless, and a total of
        // quantities adds hours to installment numbers.
        return ['amount'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->toBase()->cursor() as $line) {
                yield [
                    'employee_code' => $line->employee_code,
                    'employee_name' => trim($line->first_name.' '.$line->last_name),
                    'office' => $line->office_name,
                    'department' => $line->department_name,
                    'component_code' => $line->code,
                    'component' => $line->label,
                    'type' => ucfirst((string) $line->type),
                    'quantity' => $line->quantity,
                    'rate' => $line->rate,
                    'amount' => $line->amount,
                    'formula' => $line->formula,
                    'source' => $line->source,
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $run = $this->run($parameters);

        $signs = $this->signatories($run);

        return new ReportMeta(
            title: 'Payroll detail for accounting',
            subtitle: $run === null
                ? null
                : sprintf('%s — run v%d (%s)', (string) $run->period?->label(), $run->version, $run->status->label()),
            periodLocked: $run?->status?->isFinal() ?? false,
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
            ->orderBy('employees.employee_code')
            ->orderBy('payslip_lines.sequence')
            ->select([
                'payslip_lines.code',
                'payslip_lines.label',
                'payslip_lines.type',
                'payslip_lines.quantity',
                'payslip_lines.rate',
                'payslip_lines.amount',
                'payslip_lines.formula',
                'payslip_lines.source',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                ...$this->assignmentColumns(),
            ]);
    }
}
