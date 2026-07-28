<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
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
 * RPT-02 #10 — Payroll variance against a previous run.
 *
 * **This report exists as Correction C10c defines it, and every part of that
 * definition was a decision rather than an obvious reading.** RPT-02 ticked
 * "payroll variance vs previous month" and defined nothing: not variance of
 * what, not at what grain, not whether small movements are hidden.
 *
 * C10c settled it as **per employee AND per component**, because the question a
 * payroll manager actually asks is "who got paid differently this month, and
 * why" — and a change in net without the component that moved it explains
 * nothing. So each employee contributes a **Net pay** row followed by one row
 * per component that moved.
 *
 * ⚠⚠ **A joiner or a leaver is LABELLED, never shown as a 100% variance.** This
 * is the specific outcome C10c was written to prevent: somebody who joined this
 * month has no previous figure, and `(current − 0) / 0` rendered as "+100%" —
 * or worse, as a percentage at all — is a real-looking number describing a
 * non-event. Twenty new hires would top the variance report every month and bury
 * the one person whose overtime doubled. Their delta is shown; their percentage
 * is deliberately blank, and the reason is in its own column.
 *
 * ⚠ **The same applies to a component that appears on only one side.** An
 * employee present in both runs who took a loan this month has no previous loan
 * line; that is a component added, not a 100% change.
 *
 * ⚠ **Percentages are also blank where the previous figure is zero** — for the
 * same arithmetic reason, and it is worth stating separately because a component
 * can legitimately be present with a zero amount.
 *
 * The threshold below which rows are hidden is configuration seeded at 0 (show
 * everything), per C10c: hiding rows is a decision the business takes
 * deliberately rather than one it inherits from a default.
 */
class PayrollVarianceReport implements ReportDefinition
{
    use CountsRows, ResolvesAssignments;

    /** The pseudo-component under which each employee's net is compared. */
    private const NET = '__net';

    public function key(): string
    {
        return 'payroll_variance';
    }

    public function name(): string
    {
        return 'Payroll variance';
    }

    public function description(): string
    {
        return 'Two payroll runs compared per employee and per component, with joiners and leavers labelled rather than shown as a total change.';
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
            ReportParameter::payrollRun('run_id', 'This run'),
            ReportParameter::payrollRun('compare_run_id', 'Compared with'),
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::boolean(
                'movements_only',
                'Only what moved',
                default: true,
                help: 'Hide components that came out identical in both runs. Turn this off for a full side-by-side.',
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('component', 'Component', 22),
            ReportColumn::money('previous', 'Previous'),
            ReportColumn::money('current', 'Current'),
            ReportColumn::money('delta', 'Change'),
            // Text, not decimal: it is blank wherever a percentage would be a
            // fabrication, and a numeric column cannot hold "blank on purpose".
            ReportColumn::text('delta_percent', 'Change %', 10),
            ReportColumn::text('note', 'Note', 24),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ Only the delta totals meaningfully. Summing Previous and Current
        // across a report that carries both a Net row and its component rows
        // would double-count every employee — the net IS the components.
        return ['delta'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $currentRun = $parameters->int('run_id');
            $threshold = $this->threshold();
            $movementsOnly = $parameters->bool('movements_only');

            $employee = null;

            // One ordered pass over both runs' lines together. Only one
            // employee's components are ever held — the register's technique,
            // applied to two runs at once.
            foreach ($this->query($parameters)->toBase()->cursor() as $line) {
                if ($employee !== null && $employee['id'] !== $line->employee_id) {
                    // ⚠ `foreach … yield`, never `yield from`. A generator's
                    // automatic keys restart at 0 for each inner generator, and
                    // `yield from` passes those keys straight through — so every
                    // employee's rows would arrive keyed 0,1,2 and any consumer
                    // that preserves keys (`iterator_to_array`, `->all()`) keeps
                    // only the last employee's. The writers iterate and would
                    // never have shown it; the test that caught it did.
                    foreach ($this->compare($employee, $threshold, $movementsOnly) as $row) {
                        yield $row;
                    }

                    $employee = null;
                }

                $employee ??= [
                    'id' => $line->employee_id,
                    'code' => $line->employee_code,
                    'name' => trim($line->first_name.' '.$line->last_name),
                    'department' => $line->department_name,
                    'components' => [],
                    'in_current' => false,
                    'in_previous' => false,
                ];

                $side = (int) $line->payroll_run_id === $currentRun ? 'current' : 'previous';
                $employee['in_'.$side] = true;

                $this->accumulate($employee, self::NET, 'Net pay', $side, (float) $line->net, first: true);
                $this->accumulate($employee, $line->code, $line->label, $side, (float) $line->amount);
            }

            if ($employee !== null) {
                foreach ($this->compare($employee, $threshold, $movementsOnly) as $row) {
                    yield $row;
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function accumulate(array &$employee, string $code, string $label, string $side, float $amount, bool $first = false): void
    {
        $employee['components'][$code] ??= [
            'label' => $label,
            'previous' => null,
            'current' => null,
            'first' => $first,
        ];

        // The net arrives once per *line* because it is selected from the
        // payslip, so it is assigned rather than added; component amounts are
        // added, since a payslip may carry the same code more than once.
        $employee['components'][$code][$side] = $first
            ? $amount
            : round((float) ($employee['components'][$code][$side] ?? 0) + $amount, 2);
    }

    /**
     * Turn one employee's two-sided components into report rows.
     *
     * @param  array<string, mixed>  $employee
     * @return iterable<int, array<string, mixed>>
     */
    private function compare(array $employee, float $threshold, bool $movementsOnly): iterable
    {
        // C10c's headline rule, decided once per employee rather than per row —
        // a joiner's every component would otherwise be labelled separately.
        $note = match (true) {
            ! $employee['in_previous'] => 'Joiner — not in previous run',
            ! $employee['in_current'] => 'Leaver — not in this run',
            default => null,
        };

        // Net first, then components in the order they were met.
        uasort($employee['components'], fn ($a, $b) => ($b['first'] ? 1 : 0) <=> ($a['first'] ? 1 : 0));

        foreach ($employee['components'] as $code => $component) {
            $previous = $component['previous'];
            $current = $component['current'];
            $delta = round((float) $current - (float) $previous, 2);

            if ($movementsOnly && abs($delta) < 0.005 && $code !== self::NET) {
                continue;
            }

            if (abs($delta) < $threshold) {
                continue;
            }

            yield [
                'employee_code' => $employee['code'],
                'employee_name' => $employee['name'],
                'department' => $employee['department'],
                'component' => $component['label'],
                'previous' => $previous,
                'current' => $current,
                'delta' => $delta,
                'delta_percent' => $this->percent($previous, $current, $note !== null),
                'note' => $note ?? $this->componentNote($previous, $current),
            ];
        }
    }

    /**
     * ⚠ The whole point of C10c, in one function.
     *
     * A percentage needs something to be a percentage *of*. Where there is no
     * previous figure — a joiner, or a component that did not exist last month —
     * there is no such thing, and the honest rendering is nothing at all. The
     * tempting alternatives are both worse: 100% is arithmetically indefensible
     * and reads as a real movement, and 0% reads as "no change" on a row where
     * money appeared out of nowhere.
     */
    private function percent(mixed $previous, mixed $current, bool $oneSidedEmployee): ?string
    {
        if ($oneSidedEmployee || $previous === null || $current === null) {
            return null;
        }

        $previous = round((float) $previous, 2);

        if (abs($previous) < 0.005) {
            return null;
        }

        $percent = (round((float) $current, 2) - $previous) / abs($previous) * 100;

        return sprintf('%+.1f%%', $percent);
    }

    private function componentNote(mixed $previous, mixed $current): ?string
    {
        return match (true) {
            $previous === null => 'Component added',
            $current === null => 'Component removed',
            default => null,
        };
    }

    private function threshold(): float
    {
        return (float) config('hrm.reporting.variance_threshold', 0);
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Rows are employee-components across both runs; the line count is the
        // right order of magnitude and costs one cheap count.
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $current = $this->run($parameters->int('run_id'));
        $previous = $this->run($parameters->int('compare_run_id'));

        return new ReportMeta(
            title: 'Payroll variance',
            subtitle: $current === null || $previous === null
                ? null
                : sprintf(
                    '%s v%d compared with %s v%d',
                    (string) $current->period?->label(), $current->version,
                    (string) $previous->period?->label(), $previous->version,
                ),
            periodLocked: ($current?->status?->isFinal() ?? false) && ($previous?->status?->isFinal() ?? false),
            lockNote: 'A joiner or leaver shows a change but no percentage — there is no previous figure for one to be a percentage of.',
        );
    }

    private function run(?int $id): ?PayrollRun
    {
        return $id === null ? null : PayrollRun::with('period')->find($id);
    }

    /**
     * Both runs' payslip lines in one stream, ordered by employee.
     *
     * ⚠ A full outer join is what this conceptually wants and neither MySQL nor
     * SQLite has one — which is why the two sides arrive interleaved and are
     * matched in PHP instead. Two separate queries would have been the other
     * option and a worse one: they cannot be walked together without holding one
     * of them entirely in memory.
     */
    private function query(ReportParameters $parameters): Builder
    {
        $runs = array_values(array_filter([
            $parameters->int('run_id'),
            $parameters->int('compare_run_id'),
        ]));

        $current = $this->run($parameters->int('run_id'));
        $asAt = ($current?->period?->end_date ?? now())->toDateString();

        $query = Payslip::query()
            ->join('payslip_lines', 'payslip_lines.payslip_id', '=', 'payslips.id')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id');

        $query = $this->joinAssignmentAsAt($query, 'payslips.employee_id', $asAt);
        $query = $this->filterByAssignment($query, $parameters);

        return $query
            ->whereIn('payslips.payroll_run_id', $runs === [] ? [0] : $runs)
            ->orderBy('employees.employee_code')
            ->orderBy('payslips.payroll_run_id')
            ->orderBy('payslip_lines.sequence')
            ->select([
                'payslips.employee_id',
                'payslips.payroll_run_id',
                'payslips.net',
                'payslip_lines.code',
                'payslip_lines.label',
                'payslip_lines.amount',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                ...$this->assignmentColumns(),
            ]);
    }
}
