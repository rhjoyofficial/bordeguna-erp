<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Models\ManualPayrollLine;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Concerns\ResolvesAssignments;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Support\LazyCollection;

/**
 * W2 — the parallel-run reconciliation (ROL-02/03/04, Correction C16).
 *
 * **This is the go-live acceptance artefact.** ROL-04 requires HR, Finance and
 * Management to approve jointly, having reviewed this. It is the last thing
 * standing between a spreadsheet that has paid nine people correctly for years
 * and a system that has never paid anybody.
 *
 * ⚠⚠⚠ **THIS REPORT NEVER SAYS "PASS", AND THAT IS CORRECTION C16b, NOT A
 * STYLISTIC CHOICE.**
 *
 * ROL-03 has been paraphrased throughout this project as "match within ≤1 BDT
 * (rounding only)". The source questionnaire is stricter:
 *
 *   "No difference caused by formula, attendance, leave or policy is
 *    acceptable. Only for decimal rounding is a difference of at most 1 BDT
 *    acceptable."
 *
 * **One BDT is a ceiling on one named cause, not a tolerance band.** A 0.40 BDT
 * difference produced by a wrong overtime rate is *not* acceptable, and a report
 * that classified by magnitude would pass it silently — the exact defect the
 * gate exists to catch, and the exact shape this project has found five times.
 *
 * So a small difference is reported as **"Within rounding range — confirm"**,
 * which is a question put to a human, not a verdict. Whether rounding is the
 * *actual* cause is a judgement about a specific employee's specific component,
 * and the system does not have the standing to make it. Where the system cannot
 * know, it must not guess: here the invented answer would be the go-live
 * approval itself.
 *
 * ⚠ **It recomputes nothing.** System figures are read from `payslips` and
 * `payslip_lines` exactly as `PayrollLineDetailReport` reads them. A
 * reconciliation that recalculated its own side would be comparing the manual
 * sheet against a second implementation and could agree with neither.
 */
class ParallelRunReconciliationReport implements ReportDefinition
{
    use CountsRows, ResolvesAssignments;

    /** The pseudo-item under which each employee's net is compared. */
    private const NET = '__net';

    /** ROL-03's ceiling, in BDT. ⚠ A ceiling on rounding, not a pass mark. */
    private const ROUNDING_CEILING = 1.00;

    public function key(): string
    {
        return 'parallel_run_reconciliation';
    }

    public function name(): string
    {
        return 'Parallel run reconciliation';
    }

    public function description(): string
    {
        return 'System payroll compared line by line against the imported manual payroll for the same period. Differences are classified, never passed: ROL-03 permits 1 BDT only for decimal rounding and nothing for a formula, attendance, leave or policy cause.';
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
            ReportParameter::payrollRun('run_id', 'System payroll run'),
            ReportParameter::office(),
            ReportParameter::department(),
            ReportParameter::boolean(
                'differences_only',
                'Only what differs',
                default: false,
                help: 'Hide items that matched exactly. Leave this off for the sign-off copy — three people are approving what DID match as much as what did not.',
            ),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('item', 'Item', 22),
            ReportColumn::money('system_amount', 'System'),
            ReportColumn::money('manual_amount', 'Manual'),
            ReportColumn::money('difference', 'Difference'),
            ReportColumn::text('status', 'Status', 30),
            ReportColumn::text('note', 'Note', 34),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ Only the difference totals meaningfully. Summing System and Manual
        // across a report carrying both a Net row and its item rows would
        // double-count every employee — the net IS the items.
        //
        // ⚠ And a total difference of zero means NOTHING here: an employee
        // overpaid 500 and another underpaid 500 sum to a clean zero. The
        // per-line status is the finding; this total is a arithmetic check only.
        return ['difference'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $run = PayrollRun::query()->find($parameters->int('run_id'));

            if ($run === null) {
                return;
            }

            $differencesOnly = $parameters->bool('differences_only');

            // The manual sheet for one period is inherently small — one payroll
            // month for one company — so it is loaded once as a lookup rather
            // than joined. This does not break the streaming rule: the report's
            // OWN rows are still produced lazily from a cursor.
            $manual = $this->manualFor($run->payroll_period_id);

            $employee = null;

            foreach ($this->query($parameters, $run)->toBase()->cursor() as $line) {
                if ($employee !== null && $employee['id'] !== $line->employee_id) {
                    // ⚠ `foreach … yield`, never `yield from` — a generator's
                    // automatic keys restart at 0 per inner generator, so every
                    // employee's rows would arrive keyed 0,1,2 and any
                    // key-preserving consumer keeps only the last employee's.
                    foreach ($this->compare($employee, $manual, $differencesOnly) as $row) {
                        yield $row;
                    }

                    $employee = null;
                }

                $employee ??= [
                    'id' => $line->employee_id,
                    'code' => $line->employee_code,
                    'name' => trim($line->first_name.' '.$line->last_name),
                    'items' => [],
                ];

                $this->accumulate($employee, self::NET, 'Net pay', (float) $line->net, assign: true);
                $this->accumulate($employee, $line->code, $line->label, (float) $line->amount);
            }

            if ($employee !== null) {
                foreach ($this->compare($employee, $manual, $differencesOnly) as $row) {
                    yield $row;
                }
            }

            // ⚠ Anyone left in the manual sheet was NOT in the system payroll.
            // Emitting them is the entire point of a parallel run: somebody the
            // manual process pays and the system does not is the most serious
            // finding available, and dropping them would hide it completely.
            foreach ($this->orphans($manual) as $row) {
                yield $row;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function accumulate(array &$employee, string $code, string $label, float $amount, bool $assign = false): void
    {
        $employee['items'][$code] ??= ['label' => $label, 'amount' => 0.0, 'first' => $assign];

        // Net arrives once per line because it is selected from the payslip, so
        // it is assigned; item amounts are added, since a payslip may carry the
        // same code more than once.
        $employee['items'][$code]['amount'] = $assign
            ? $amount
            : round($employee['items'][$code]['amount'] + $amount, 2);
    }

    /**
     * One employee's system items compared against their manual figures.
     *
     * @param  array<string, mixed>  $employee
     * @param  array<string, array<string, mixed>>  $manual
     * @return iterable<int, array<string, mixed>>
     */
    private function compare(array $employee, array &$manual, bool $differencesOnly): iterable
    {
        $theirs = $manual[$this->manualKey($employee['code'])] ?? null;
        unset($manual[$this->manualKey($employee['code'])]);

        // Net first, then the rest in a stable order — the sign-off reads net.
        $items = $employee['items'];
        uksort($items, fn ($a, $b) => $a === self::NET ? -1 : ($b === self::NET ? 1 : strcmp($a, $b)));

        foreach ($items as $code => $item) {
            $manualAmount = $theirs['items'][$code]['amount'] ?? null;
            $seen[$code] = true;

            $row = $this->row(
                $employee,
                $item['label'],
                (float) $item['amount'],
                $manualAmount === null ? null : (float) $manualAmount,
                $theirs === null,
            );

            if ($differencesOnly && $row['status'] === 'Match') {
                continue;
            }

            yield $row;
        }

        // Items the manual sheet carries that the system produced no line for.
        // ⚠ Listed, never folded in as a zero (C16c) — "the system charged no
        // loan repayment" and "the system produced nothing here" are different
        // findings and only one of them is a defect.
        foreach ($theirs['items'] ?? [] as $code => $item) {
            if (isset($seen[$code])) {
                continue;
            }

            yield [
                'employee_code' => $employee['code'],
                'employee_name' => $employee['name'],
                'item' => $item['label'],
                'system_amount' => null,
                'manual_amount' => (float) $item['amount'],
                'difference' => null,
                'status' => 'Manual only',
                'note' => 'On the manual sheet with no matching system line.',
            ];
        }
    }

    /**
     * Employees on the manual sheet who had no system payslip at all.
     *
     * @param  array<string, array<string, mixed>>  $manual
     * @return iterable<int, array<string, mixed>>
     */
    private function orphans(array $manual): iterable
    {
        foreach ($manual as $entry) {
            $net = $entry['items'][self::NET]['amount'] ?? null;

            yield [
                'employee_code' => $entry['ref'],
                'employee_name' => $entry['name'] ?? '(not in system)',
                'item' => 'Net pay',
                'system_amount' => null,
                'manual_amount' => $net === null ? null : (float) $net,
                'difference' => null,
                'status' => 'Not in system payroll',
                'note' => 'Paid by the manual process; the system produced no payslip.',
            ];
        }
    }

    /**
     * ⚠⚠ WHERE CORRECTION C16b LIVES. Read the class docblock before changing
     * anything here.
     *
     * @param  array<string, mixed>  $employee
     * @return array<string, mixed>
     */
    private function row(array $employee, string $label, float $system, ?float $manualAmount, bool $employeeMissing): array
    {
        $base = [
            'employee_code' => $employee['code'],
            'employee_name' => $employee['name'],
            'item' => $label,
            'system_amount' => $system,
            'manual_amount' => $manualAmount,
        ];

        if ($employeeMissing) {
            // ⚠ Labelled, never rendered as a total difference (C16d, C10c's
            // rule reused). Somebody absent from the manual sheet is a fact
            // about the sheet, not a payroll error of the full amount.
            return [...$base, 'difference' => null, 'status' => 'Not on manual sheet',
                'note' => 'No manual figures were imported for this employee.'];
        }

        if ($manualAmount === null) {
            return [...$base, 'difference' => null, 'status' => 'System only',
                'note' => 'The system produced this line; the manual sheet does not name it.'];
        }

        $difference = round($system - $manualAmount, 2);

        if (abs($difference) < 0.005) {
            return [...$base, 'difference' => 0.0, 'status' => 'Match', 'note' => ''];
        }

        if (abs($difference) <= self::ROUNDING_CEILING) {
            // ⚠⚠ NOT A PASS. ROL-03 allows up to 1 BDT *for decimal rounding*
            // and nothing at all for a formula, attendance, leave or policy
            // cause. Only a human looking at this specific line can say which
            // this is. Calling it "within tolerance" would decide that question
            // on the system's behalf, by size alone.
            return [...$base, 'difference' => $difference, 'status' => 'Within rounding range — confirm',
                'note' => 'Small enough that rounding could explain it. ROL-03 accepts this ONLY if rounding is the actual cause; a human must confirm.'];
        }

        return [...$base, 'difference' => $difference, 'status' => 'Discrepancy',
            'note' => 'Exceeds ROL-03. Not acceptable regardless of cause.'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manualFor(?int $periodId): array
    {
        if ($periodId === null) {
            return [];
        }

        $out = [];

        foreach (ManualPayrollLine::query()->forPeriod($periodId)->with('employee')->cursor() as $line) {
            $ref = $line->employee?->employee_code ?? $line->source_employee_ref;
            $key = $this->manualKey($ref);

            $out[$key] ??= [
                'ref' => $ref,
                'name' => $line->employee !== null
                    ? trim($line->employee->first_name.' '.$line->employee->last_name)
                    : $line->source_employee_ref,
                'items' => [],
            ];

            // The importer normalises headings to the same keys the payslip
            // lines use, plus `__net` for the net figure.
            $code = $line->item_key === 'net' ? self::NET : $line->item_key;

            $out[$key]['items'][$code] = [
                'label' => $line->item_label,
                'amount' => (float) $line->amount,
            ];
        }

        return $out;
    }

    private function manualKey(?string $ref): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $ref) ?? '');
    }

    private function query(ReportParameters $parameters, PayrollRun $run)
    {
        $asAt = ($run->period?->end_date ?? now())->toDateString();

        $query = Payslip::query()
            ->join('payslip_lines', 'payslip_lines.payslip_id', '=', 'payslips.id')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id');

        $query = $this->joinAssignmentAsAt($query, 'payslips.employee_id', $asAt);
        $query = $this->filterByAssignment($query, $parameters);

        return $query
            ->where('payslips.payroll_run_id', $run->id)
            ->orderBy('employees.employee_code')
            ->orderBy('payslip_lines.sequence')
            ->select([
                'payslips.employee_id',
                'payslips.net',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'payslip_lines.code',
                'payslip_lines.label',
                'payslip_lines.amount',
            ]);
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        $run = PayrollRun::query()->find($parameters->int('run_id'));

        if ($run === null) {
            return 0;
        }

        // System payslip lines plus the manual lines. The real row count is
        // slightly lower where the two sides match up, which is exactly what
        // "estimated" is contractually allowed to mean — it only picks between
        // rendering inline and queueing.
        return $this->query($parameters, $run)->toBase()->getCountForPagination()
            + ManualPayrollLine::query()->forPeriod((int) $run->payroll_period_id)->count();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $run = PayrollRun::query()->find($parameters->int('run_id'));

        return new ReportMeta(
            title: 'Parallel run reconciliation',
            subtitle: $run === null ? null : sprintf('%s v%d against the imported manual payroll', (string) $run->period?->label(), $run->version),
            // ⚠ Stated on every copy, including the one three people sign.
            note: 'ROL-03: a difference of up to 1 BDT is acceptable ONLY where decimal rounding is the actual cause. No difference caused by formula, attendance, leave or policy is acceptable at any size. This report classifies differences; it does not approve them.',
        );
    }
}
