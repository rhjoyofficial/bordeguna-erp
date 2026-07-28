<?php

namespace App\Modules\Hrm\Payroll\Disbursement;

use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\Definitions\CostAllocationReport;
use App\Modules\Hrm\Reporting\Definitions\PayrollLineDetailReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\Writers\CsvWriter;

/**
 * The DIS-05 accounting export.
 *
 * DIS-05's own recommendation carried the ✔: an auditable export in Phase 1,
 * with journal posting from an approved payroll snapshot once an Accounting
 * module exists. So this posts nothing and knows nothing about a chart of
 * accounts — it hands Finance the figures in a form they can reconcile and key.
 *
 * Two views of the same run, because the two questions Finance actually asks are
 * different ones:
 *
 *  - **summary** — what this payroll cost, by component. The journal-entry shape,
 *    ready for whoever posts it.
 *  - **detail** — every line of every payslip, with the office and department it
 *    belongs to. This is the auditable half: a summary figure nobody can drill
 *    into is not auditable.
 *
 * ⚠⚠ **W3c re-expressed both views as report definitions, and this class now
 * produces nothing of its own.** The plan's reuse rule was "exactly one path
 * from data to file", and 3f's hand-rolled `fputcsv` was a second one. The rows
 * come from `PayrollLineDetailReport` and `CostAllocationReport`; this keeps
 * only the DIS-05 route's filename and its reconciliation footer.
 *
 * **Moving it fixed a real defect that had shipped.** The old detail resolved
 * each row's office and department through `$payslip->employee->currentAssignment()`
 * — *today's* assignment. Re-running the export for a closed payroll after
 * somebody transferred silently refiled their whole month's cost under the new
 * department, and the file still footed. The definitions resolve the assignment
 * as at the run's period end.
 *
 * The same two views are now also available from the reports screen in xlsx, PDF
 * and print, which RPT-03 asked for and this class could never have offered.
 *
 * Only calculated figures are exported and none is recomputed here. A payroll
 * export that disagrees with the payslips it came from would be worse than none.
 */
class AccountingExport
{
    public const SUMMARY = 'summary';

    public const DETAIL = 'detail';

    public function __construct(
        private readonly ReportRunner $runner,
        private readonly CsvWriter $writer,
        private readonly PayrollLineDetailReport $lineDetail,
        private readonly CostAllocationReport $allocation,
    ) {}

    public function filename(PayrollRun $run, string $mode): string
    {
        return sprintf(
            'payroll-%s-v%d-%s.csv',
            str_replace(' ', '-', strtolower((string) $run->period?->label())),
            $run->version,
            $mode,
        );
    }

    public function render(PayrollRun $run, string $mode = self::SUMMARY): string
    {
        return $mode === self::DETAIL ? $this->detail($run) : $this->summary($run);
    }

    /**
     * One row per component across the whole run, plus the reconciliation block.
     */
    private function summary(PayrollRun $run): string
    {
        $csv = $this->csv($this->allocation, [
            'run_id' => $run->id,
            // The whole-run grain: no office or department grouping, which is
            // the journal-entry shape DIS-05 asks for.
            'grain' => 'run',
        ]);

        // ⚠ The three run-level figures are appended rather than derived from
        // the rows above, and that is the point of them: they come from the
        // `payroll_runs` row, so the export **reconciles against the payroll
        // screen** instead of merely agreeing with itself. A total computed from
        // the same rows it sits under can never disagree, and therefore can
        // never catch anything.
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, []);
        // Six fields, matching the six columns the run-grain report emits — a
        // reconciliation block that does not line up under its own report is
        // read as a different table.
        fputcsv($handle, ['TOTALS', '', 'Earnings', $run->employee_count, number_format((float) $run->total_earnings, 2, '.', ''), '']);
        fputcsv($handle, ['', '', 'Deductions', '', number_format((float) $run->total_deductions, 2, '.', ''), '']);
        fputcsv($handle, ['', '', 'Net payable', '', number_format((float) $run->total_net, 2, '.', ''), '']);

        rewind($handle);
        $totals = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv.$totals;
    }

    /**
     * One row per payslip line, carrying where the cost belongs.
     */
    private function detail(PayrollRun $run): string
    {
        return $this->csv($this->lineDetail, ['run_id' => $run->id]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function csv(object $definition, array $parameters): string
    {
        $dataset = $this->runner->run($definition, new ReportParameters($parameters));
        $path = $this->writer->write($dataset);

        $csv = (string) file_get_contents($path);
        @unlink($path);

        return $csv;
    }
}
