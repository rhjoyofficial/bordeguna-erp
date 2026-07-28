<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Reporting\Concerns\ResolvesAssignments;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * RPT-02 #13 — Business cost allocation.
 *
 * What a payroll run cost, grouped by office and department, and by component
 * within them.
 *
 * ⚠⚠ **This is a GROUPING, not a split, and the distinction is a rule rather
 * than a preference.** Correction C7c settled that an employee has exactly one
 * salary structure, owned by the business holding their active primary
 * assignment, and **that business pays in full**. So there is no proportional
 * allocation across businesses to compute here, because none was ever
 * authorised. `AccountingExport`'s detail rows already carry the office and
 * department columns that "a later business-wise cost posting will group on";
 * this report is that grouping and nothing more.
 *
 * **If a shared employee's cost should actually be split between businesses,
 * that is a new rule contradicting C7c and needs its own correction.** It will
 * not be invented here — a split invented in a report is a split nobody agreed
 * to, arriving in the accounts.
 *
 * ⚠ **The office and department are resolved as at the run's period end**, like
 * every other payroll report, so a transfer in the last week does not move a
 * whole month's cost into the new department's line.
 */
class CostAllocationReport implements ReportDefinition
{
    use ResolvesAssignments;

    public function key(): string
    {
        return 'payroll_cost_allocation';
    }

    public function name(): string
    {
        return 'Cost allocation';
    }

    public function description(): string
    {
        return 'What a payroll run cost by office and department, broken down by component. A grouping of cost, not a split of it.';
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
            ReportParameter::select('grain', 'Detail', [
                ['value' => 'component', 'label' => 'By component within each department'],
                ['value' => 'department', 'label' => 'By department only'],
                // The DIS-05 accounting summary: the journal-entry shape, one
                // row per component across the whole run with no organisational
                // grouping at all. It lives here rather than in a report of its
                // own because it is the same query with one grouping removed,
                // and two definitions producing the same figures differently is
                // exactly what re-expressing AccountingExport was meant to end.
                ['value' => 'run', 'label' => 'By component across the whole run (accounting summary)'],
            ], default: 'component'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        $grain = $parameters?->string('grain') ?? 'component';
        $columns = [];

        // The whole-run grain drops the organisational columns entirely: they
        // would be blank on every row, and a column of blanks is worse than no
        // column because it reads as missing data.
        if ($grain !== 'run') {
            $columns[] = ReportColumn::text('office', 'Office', 22);
            $columns[] = ReportColumn::text('department', 'Department', 22);
        }

        if ($grain !== 'department') {
            // "Component code", not "Code". This header is the DIS-05 export's
            // own, and Finance keys from it — a refactor that quietly renamed a
            // column somebody's spreadsheet reads is a break, not a tidy-up.
            $columns[] = ReportColumn::text('component_code', 'Component code', 16);
            $columns[] = ReportColumn::text('component', 'Component', 22);
            $columns[] = ReportColumn::text('type', 'Type', 12);
        }

        return [
            ...$columns,
            ReportColumn::integer('employees', 'People', 9),
            ReportColumn::money('amount', 'Amount'),
            ReportColumn::money('net_amount', 'Net effect'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠⚠ **`amount` is deliberately NOT totalled, and this was caught in the
        // live check rather than by any test.** Payslip lines store every figure
        // positive — the *type* decides which side of the payslip it falls on,
        // which is what stops a stray minus sign turning a penalty into a
        // payment. Summing the column therefore ADDS deductions to earnings: the
        // first live run footed to 111,200 on a payroll whose earnings were
        // 106,200 and whose net was 101,200. It matched nothing, and it looked
        // entirely plausible.
        //
        // `net_amount` carries the sign the type implies, so its total is a real
        // figure — what this slice of the payroll actually cost. `amount` keeps
        // its positive per-component value because DIS-05's export has always
        // shown it that way and Finance keys from it.
        //
        // `employees` is not totalled either: at component grain the same person
        // is counted under every component they received, so the column adds up
        // to a number that is nobody's headcount.
        return ['net_amount'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $grain = $parameters->string('grain') ?? 'component';

            foreach ($this->query($parameters)->toBase()->cursor() as $group) {
                $row = [];

                if ($grain !== 'run') {
                    $row['office'] = $group->office_name ?? '(unassigned)';
                    $row['department'] = $group->department_name ?? '(unassigned)';
                }

                if ($grain !== 'department') {
                    $row['component_code'] = $group->code;
                    $row['component'] = $group->label;
                    $row['type'] = ucfirst((string) $group->type);
                }

                yield [
                    ...$row,
                    'employees' => (int) $group->employees,
                    'amount' => $group->amount,
                    // Signed by the component's own type. At department grain a
                    // row mixes both, so the signed sum is computed in SQL there
                    // rather than flipped here.
                    'net_amount' => $group->net_amount,
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Departments times components — tens, not thousands. Counting a grouped
        // query would cost a subquery over the whole run to learn nothing.
        return 0;
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $run = $this->run($parameters);

        return new ReportMeta(
            title: 'Cost allocation',
            subtitle: $run === null
                ? null
                : sprintf('%s — run v%d (%s)', (string) $run->period?->label(), $run->version, $run->status->label()),
            periodLocked: $run?->status?->isFinal() ?? false,
            lockNote: 'Cost is grouped by where each employee was assigned, never split between businesses (C7c).',
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
        $grain = $parameters->string('grain') ?? 'component';
        $byComponent = $grain !== 'department';

        $query = PayslipLine::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id');

        $query = $this->joinAssignmentAsAt($query, 'payslips.employee_id', $asAt);
        $query = $this->filterByAssignment($query, $parameters);

        $groups = $grain === 'run' ? [] : ['offices.name', 'departments.name'];
        $select = [
            DB::raw('COUNT(DISTINCT payslips.employee_id) as employees'),
            DB::raw('SUM(payslip_lines.amount) as amount'),
            // The signed view, so a department subtotal is what that department
            // actually cost rather than the sum of two opposing quantities.
            DB::raw("SUM(CASE WHEN payslip_lines.type = 'deduction'"
                .' THEN -payslip_lines.amount ELSE payslip_lines.amount END) as net_amount'),
        ];

        if ($grain !== 'run') {
            $select = [
                'offices.name as office_name',
                'departments.name as department_name',
                ...$select,
            ];
        }

        if ($byComponent) {
            $groups = [...$groups, 'payslip_lines.code', 'payslip_lines.label', 'payslip_lines.type'];
            $select = [
                ...$select,
                'payslip_lines.code',
                'payslip_lines.label',
                'payslip_lines.type',
            ];
        }

        return $query
            ->when(
                $parameters->int('run_id'),
                fn (Builder $q, int $id) => $q->where('payslips.payroll_run_id', $id),
            )
            ->groupBy($groups)
            ->when($grain !== 'run', fn (Builder $q) => $q
                ->orderBy('offices.name')
                ->orderBy('departments.name'))
            ->when($byComponent, fn (Builder $q) => $q->orderByRaw('min(payslip_lines.sequence)'))
            ->select($select);
    }
}
